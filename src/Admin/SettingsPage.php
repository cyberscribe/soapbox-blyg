<?php
/**
 * Settings.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Admin;

use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Origins\Registry;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Caching;
use SoapboxBlyg\Surface\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Settings > Soapbox for Blygger, built on the Settings API: one option, one sanitize callback.
 *
 * The sanitize callback is the one place every change to the option passes through, whether it
 * comes from this screen, from the REST settings endpoint or from update_option(). It is where a
 * locked mount is explained to a person; the lock itself is enforced in Origins\Registry, which
 * holds even if this callback is bypassed.
 */
class SettingsPage {

	const GROUP = 'soapbox_blyg';
	const PAGE  = 'soapbox-blyg';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'fields' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SOAPBOX_BLYG_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Registers the option, with its schema so the REST settings endpoint and the editor can read it.
	 */
	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'object',
				'description'       => __( 'Soapbox for Blygger settings.', 'soapbox-for-blygger' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'enabled'        => array( 'type' => 'boolean' ),
							'setup'          => array(
								'type'     => 'boolean',
								'readonly' => true,
							),
							'mount'          => array( 'type' => 'string' ),
							'layout'         => array(
								'type' => 'string',
								'enum' => Settings::LAYOUTS,
							),
							'title'          => array( 'type' => 'string' ),
							'description'    => array( 'type' => 'string' ),
							'author_name'    => array( 'type' => 'string' ),
							'author_bio'     => array( 'type' => 'string' ),
							'author_links'   => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'label' => array( 'type' => 'string' ),
										'url'   => array(
											'type'   => 'string',
											'format' => 'uri',
										),
									),
								),
							),
							'post_types'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'featured_image' => array( 'type' => 'boolean' ),
							'legacy_repair'  => array( 'type' => 'boolean' ),
							'cache_lifetime' => array(
								'type' => 'integer',
								'enum' => Caching::LIFETIMES,
							),
							'webmention'     => array( 'type' => 'boolean' ),
							'delete_data'    => array( 'type' => 'boolean' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Post types that can publish: public ones with pages of their own. Attachments are left out;
	 * a media file is not a post.
	 *
	 * @return \WP_Post_Type[]
	 */
	public static function eligible_post_types(): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name && is_post_type_viewable( $type ) ) {
				$types[ $type->name ] = $type;
			}
		}
		return $types;
	}

	/**
	 * Why a mount cannot be used on this site, or null if it can: the shape check, plus whether
	 * a page, a post or another rewrite rule already answers there.
	 *
	 * @param string $mount Candidate mount, normalised.
	 * @return string|null A translated message.
	 */
	public static function mount_problem( string $mount ) {
		if ( null !== Registry::path_error( $mount ) ) {
			return __( 'The mount path must look like /blyg/ (letters, digits, dots and hyphens between slashes). It cannot be the site root, a path WordPress itself uses, a year such as /2024/, or a file name such as robots.txt.', 'soapbox-for-blygger' );
		}
		$path = trim( $mount, '/' );

		$claimed_by = get_page_by_path( $path, OBJECT, array_keys( get_post_types( array( 'public' => true ) ) ) );
		if ( $claimed_by ) {
			/* translators: 1: mount path, 2: title of the page or post that already uses it */
			return sprintf( __( 'The path %1$s is already the address of "%2$s". Choose another, or change that page\'s slug first.', 'soapbox-for-blygger' ), esc_html( $mount ), esc_html( get_the_title( $claimed_by ) ) );
		}
		foreach ( Plugin::instance()->registry->all() as $origin ) {
			if ( PostTypeSource::ORIGIN !== $origin->key && $origin->path === $mount ) {
				/* translators: 1: mount path, 2: origin key */
				return sprintf( __( 'The path %1$s is already the mount of another blyg on this site (%2$s).', 'soapbox-for-blygger' ), esc_html( $mount ), esc_html( $origin->key ) );
			}
		}

		// Another plugin's or a post type's own rewrite rule on the same first segment (a post type
		// archive at /blyg/, say). Ours are left out of the comparison.
		$ours  = Plugin::instance()->router ? array_keys( Plugin::instance()->router->rules() ) : array();
		$rules = get_option( 'rewrite_rules' );
		foreach ( is_array( $rules ) ? array_keys( $rules ) : array() as $regex ) {
			if ( in_array( $regex, $ours, true ) ) {
				continue;
			}
			if ( 0 === strpos( ltrim( $regex, '^' ), $path . '/' ) ) {
				/* translators: %s: mount path */
				return sprintf( __( 'Something else on this site already has addresses under %s (a post type, a taxonomy or another plugin). Choose another path.', 'soapbox-for-blygger' ), esc_html( $mount ) );
			}
		}
		return null;
	}

	/**
	 * Tells the person saving the settings that something was refused. The function that does
	 * it exists only on admin screens; the option is also saved from WP-CLI, the REST API and
	 * other code, where there is nobody to tell and it must simply not be an error.
	 *
	 * @param string $setting Setting name.
	 * @param string $code    Error code.
	 * @param string $message What happened.
	 */
	private static function say( string $setting, string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( $setting, $code, $message );
		}
	}

	/**
	 * Sanitises the option. Each field is cleaned by type, and a change to the mount or the layout
	 * is refused, with the reason, once the blyg has published.
	 *
	 * @param mixed $input The value being saved.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = Settings::all();
		$clean   = $current;

		// A browser sends nothing for an unticked box, so on the settings screen (which marks its
		// submissions) a missing flag means off. Anywhere else, a missing flag means "not changed":
		// a script that sets one setting must not switch publishing off by leaving the rest out.
		$from_screen = ! empty( $input['_screen'] );
		foreach ( array( 'enabled', 'featured_image', 'legacy_repair', 'webmention', 'delete_data' ) as $flag ) {
			if ( $from_screen || array_key_exists( $flag, $input ) ) {
				$clean[ $flag ] = ! empty( $input[ $flag ] );
			}
		}
		// The earlier plugin's uninstall reads this setting too, and its tables are these tables.
		if ( Plugin::earlier_plugin_installed() ) {
			$clean['delete_data'] = false;
		}
		foreach ( array( 'title', 'description', 'author_name' ) as $text ) {
			if ( isset( $input[ $text ] ) ) {
				$clean[ $text ] = sanitize_text_field( (string) $input[ $text ] );
			}
		}
		// One of the lifetimes offered, never a number of someone's own. Anything else changes nothing.
		if ( isset( $input['cache_lifetime'] ) ) {
			$sent = $input['cache_lifetime'];
			if ( ( is_int( $sent ) || ( is_string( $sent ) && ctype_digit( $sent ) ) ) && in_array( (int) $sent, Caching::LIFETIMES, true ) ) {
				$clean['cache_lifetime'] = (int) $sent;
			} else {
				self::say( Settings::OPTION, 'cache_lifetime', __( 'The cache lifetime was not changed: it has to be one of the lifetimes in the list.', 'soapbox-for-blygger' ) );
			}
		}
		if ( isset( $input['author_bio'] ) ) {
			$clean['author_bio'] = sanitize_textarea_field( (string) $input['author_bio'] );
		}

		if ( isset( $input['author_links'] ) ) {
			$links = $input['author_links'];
			if ( is_string( $links ) ) {
				// The screen takes one link per line, written "Label | https://example.org/".
				$parsed = array();
				foreach ( preg_split( '/\r\n|\r|\n/', $links ) as $line ) {
					$parts = array_map( 'trim', explode( '|', $line, 2 ) );
					if ( 2 === count( $parts ) ) {
						$parsed[] = array(
							'label' => $parts[0],
							'url'   => $parts[1],
						);
					}
				}
				$links = $parsed;
			}
			$clean['author_links'] = array();
			foreach ( (array) $links as $link ) {
				$label = isset( $link['label'] ) ? sanitize_text_field( (string) $link['label'] ) : '';
				$url   = isset( $link['url'] ) ? esc_url_raw( (string) $link['url'], array( 'http', 'https' ) ) : '';
				if ( '' !== $label && '' !== $url ) {
					$clean['author_links'][] = array(
						'label' => $label,
						'url'   => $url,
					);
				}
			}
		}

		// As with the flags above: the screen sends nothing at all when every box is unticked, and
		// there that means none. Anywhere else a missing key means "not changed".
		if ( $from_screen || isset( $input['post_types'] ) ) {
			$chosen              = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
			$clean['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', $chosen ), array_keys( self::eligible_post_types() ) ) );
		}

		// The mount and the layout: free until the blyg has published, permanent after.
		$locked  = Plugin::instance()->registry->is_locked( PostTypeSource::ORIGIN );
		$mount   = isset( $input['mount'] ) ? '/' . trim( strtolower( sanitize_text_field( (string) $input['mount'] ) ), '/' ) . '/' : $current['mount'];
		$layout  = isset( $input['layout'] ) && in_array( $input['layout'], Settings::LAYOUTS, true ) ? $input['layout'] : $current['layout'];
		$problem = null;

		if ( $locked ) {
			$lock = Plugin::instance()->registry->locks()[ PostTypeSource::ORIGIN ];
			if ( $mount !== $lock['path'] || $layout !== $lock['layout'] ) {
				self::say(
					Settings::OPTION,
					'locked',
					__( 'The mount path and the surface layout were not changed. This blyg has published, and every address it has published is a promise to keep answering there, so they are now permanent.', 'soapbox-for-blygger' )
				);
			}
			$clean['mount']  = $lock['path'];
			$clean['layout'] = $lock['layout'];
		} else {
			$problem = $mount !== $current['mount'] ? self::mount_problem( $mount ) : null;
			if ( null !== $problem ) {
				self::say( Settings::OPTION, 'mount', $problem );
			} else {
				$clean['mount'] = $mount;
			}
			$clean['layout'] = $layout;
		}

		// Setup is done once an address and a layout have been named and accepted, in so many
		// words: both in one save. It is never undone, and a blyg that has published has plainly
		// had both.
		$chosen         = ! $locked && isset( $input['mount'], $input['layout'] ) && in_array( $input['layout'], Settings::LAYOUTS, true ) && null === $problem;
		$was_set_up     = $locked || ! empty( $current['setup'] );
		$clean['setup'] = $was_set_up || $chosen;

		// Publishing cannot be switched on before that. On the screen it takes a save of its own,
		// so the owner has seen the address and the layout as they were stored; a script may name
		// all three at once.
		if ( $clean['enabled'] && ! ( $was_set_up || ( $chosen && ! $from_screen ) ) ) {
			$clean['enabled'] = false;
			self::say(
				Settings::OPTION,
				'setup',
				__( 'Publishing was not switched on. Choose the blyg\'s mount path and surface layout and save them first: both become permanent with the first item published.', 'soapbox-for-blygger' )
			);
		}

		return $clean;
	}

	/**
	 * Adds the screen under Settings.
	 */
	public static function menu(): void {
		add_options_page(
			__( 'Soapbox for Blygger', 'soapbox-for-blygger' ),
			__( 'Soapbox for Blygger', 'soapbox-for-blygger' ),
			Capabilities::MANAGE,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * A Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'soapbox-for-blygger' ) . '</a>' );
		return $links;
	}

	/**
	 * Sections and fields.
	 */
	public static function fields(): void {
		$sections = array(
			'publishing' => array( __( 'Publishing', 'soapbox-for-blygger' ), '' ),
			'address'    => array(
				__( 'Address', 'soapbox-for-blygger' ),
				__( 'Where your blyg lives on this site. Choose before you publish: once the blyg has an item, both of these are permanent.', 'soapbox-for-blygger' ),
			),
			'identity'   => array(
				__( 'Identity', 'soapbox-for-blygger' ),
				__( 'How the blyg describes itself to readers. Leave a field empty to use the site\'s own name and tagline.', 'soapbox-for-blygger' ),
			),
			'content'    => array( __( 'Content', 'soapbox-for-blygger' ), '' ),
			'caching'    => array( __( 'Caching', 'soapbox-for-blygger' ), '' ),
			'mentions'   => array( __( 'Mentions', 'soapbox-for-blygger' ), '' ),
			'data'       => array( __( 'Data', 'soapbox-for-blygger' ), '' ),
		);
		foreach ( $sections as $id => $section ) {
			add_settings_section(
				$id,
				$section[0],
				static function () use ( $section ) {
					if ( '' !== $section[1] ) {
						echo '<p>' . esc_html( $section[1] ) . '</p>';
					}
				},
				self::PAGE
			);
		}

		$fields = array(
			'enabled'        => array( 'publishing', _x( 'Publish', 'setting label: whether the site publishes a blyg', 'soapbox-for-blygger' ) ),
			'post_types'     => array( 'publishing', __( 'What to publish', 'soapbox-for-blygger' ) ),
			'mount'          => array( 'address', __( 'Mount path', 'soapbox-for-blygger' ) ),
			'layout'         => array( 'address', __( 'Surface layout', 'soapbox-for-blygger' ) ),
			'title'          => array( 'identity', __( 'Title', 'soapbox-for-blygger' ) ),
			'description'    => array( 'identity', __( 'Description', 'soapbox-for-blygger' ) ),
			'author_name'    => array( 'identity', __( 'Author name', 'soapbox-for-blygger' ) ),
			'author_bio'     => array( 'identity', __( 'Author bio', 'soapbox-for-blygger' ) ),
			'author_links'   => array( 'identity', __( 'Author links', 'soapbox-for-blygger' ) ),
			'featured_image' => array( 'content', __( 'Featured image', 'soapbox-for-blygger' ) ),
			'legacy_repair'  => array( 'content', __( 'Older markup', 'soapbox-for-blygger' ) ),
			'cache_lifetime' => array( 'caching', __( 'Cache lifetime', 'soapbox-for-blygger' ) ),
			'webmention'     => array( 'mentions', __( 'Receive mentions', 'soapbox-for-blygger' ) ),
			'delete_data'    => array( 'data', __( 'When the plugin is deleted', 'soapbox-for-blygger' ) ),
		);
		foreach ( $fields as $key => $field ) {
			add_settings_field(
				$key,
				$field[1],
				array( __CLASS__, 'field' ),
				self::PAGE,
				$field[0],
				array(
					'key'       => $key,
					'label_for' => in_array( $key, array( 'post_types', 'layout' ), true ) ? '' : 'soapbox-blyg-' . $key,
				)
			);
		}
	}

	/**
	 * Renders one field.
	 *
	 * @param array $args key: the setting.
	 */
	public static function field( $args ): void {
		$key    = $args['key'];
		$value  = Settings::get( $key );
		$name   = Settings::OPTION . '[' . $key . ']';
		$id     = 'soapbox-blyg-' . $key;
		$locked = Plugin::instance()->registry->is_locked( PostTypeSource::ORIGIN );
		$note   = static function ( string $text ) {
			echo '<p class="description">' . esc_html( $text ) . '</p>';
		};
		$check  = static function ( string $label ) use ( $name, $id, $value ) {
			printf(
				'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				checked( (bool) $value, true, false ),
				esc_html( $label )
			);
		};

		switch ( $key ) {
			case 'enabled':
				if ( ! $locked && ! Settings::get( 'setup' ) ) {
					// First run: the address and the layout are chosen and saved before this can be ticked.
					printf(
						'<label><input type="checkbox" id="%1$s" disabled> %2$s</label>',
						esc_attr( $id ),
						esc_html__( 'Publish this site\'s content as a blyg', 'soapbox-for-blygger' )
					);
					$note( __( 'Not available yet. First choose the mount path and the surface layout under "Address" below and save. Both become permanent with the first item published, so they are yours to decide before anything is. Then come back and tick this.', 'soapbox-for-blygger' ) );
					break;
				}
				$check( __( 'Publish this site\'s content as a blyg', 'soapbox-for-blygger' ) );
				$note( __( 'With this off, the site serves no blyg and advertises none. Nothing already published is deleted; it stops being served until this is switched back on. Switching it on publishes nothing by itself: posts join the blyg as they are saved, and existing posts when you run the backfill from Tools.', 'soapbox-for-blygger' ) );
				break;

			case 'post_types':
				echo '<fieldset>';
				foreach ( self::eligible_post_types() as $type ) {
					printf(
						'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label><br>',
						esc_attr( $name ),
						esc_attr( $type->name ),
						checked( in_array( $type->name, (array) $value, true ), true, false ),
						esc_html( $type->labels->name )
					);
				}
				echo '</fieldset>';
				$note( __( 'Every published, public item of the ticked types becomes an item of the blyg, and stays one. Unticking a type stops new items and new versions; what is already published stays until the post itself is unpublished or deleted, which withdraws it.', 'soapbox-for-blygger' ) );
				break;

			case 'mount':
				printf(
					'<code>%1$s</code><input type="text" class="regular-text code" id="%2$s" name="%3$s" value="%4$s" %5$s>',
					esc_html( untrailingslashit( home_url() ) ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					$locked ? 'readonly' : ''
				);
				$note(
					$locked
						? __( 'Permanent. This blyg has published, and readers hold its addresses; moving it would break every one of them.', 'soapbox-for-blygger' )
						: __( 'The address of the blyg, and its identity to every reader. /blyg/ is the convention, and the first place a reader looks.', 'soapbox-for-blygger' )
				);
				break;

			case 'layout':
				$layouts = array(
					'fixed'     => array(
						__( 'Fixed paths', 'soapbox-for-blygger' ),
						__( 'Blygger 0.3, understood by every reader. The protocol\'s own file names under the mount (feed.xml, items/index.json, items/{id}.json). Check Site Health afterwards: if your host caches them at the edge, exclude the mount path from that cache.', 'soapbox-for-blygger' ),
					),
					'templated' => array(
						__( 'Templated', 'soapbox-for-blygger' ),
						__( 'Blygger 0.4 draft (decision #51). Needs a reader that supports manifest-located surfaces; most don\'t yet. The manifest says where everything else is, and the documents are served through the REST API. Managed hosts often cache paths ending in .json and .xml for weeks, which would keep serving an item after you withdrew it; this layout avoids those paths.', 'soapbox-for-blygger' ),
					),
				);
				echo '<fieldset>';
				foreach ( $layouts as $layout => $text ) {
					printf(
						'<label><input type="radio" name="%1$s" value="%2$s" %3$s %4$s> <strong>%5$s</strong></label><p class="description" style="margin:0 0 .75em 1.6em">%6$s</p>',
						esc_attr( $name ),
						esc_attr( $layout ),
						checked( $value, $layout, false ),
						$locked && $value !== $layout ? 'disabled' : '',
						esc_html( $text[0] ),
						esc_html( $text[1] )
					);
				}
				echo '</fieldset>';
				if ( $locked ) {
					$note( __( 'Permanent, for the same reason as the mount path.', 'soapbox-for-blygger' ) );
				}
				break;

			case 'title':
			case 'description':
			case 'author_name':
				$identity    = Settings::identity();
				$placeholder = array(
					'title'       => $identity['title'],
					'description' => $identity['description'],
					'author_name' => $identity['author']['name'],
				);
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( '' === (string) $value ? $placeholder[ $key ] : '' )
				);
				break;

			case 'author_bio':
				printf( '<textarea class="large-text" rows="3" id="%1$s" name="%2$s">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
				break;

			case 'author_links':
				$lines = array();
				foreach ( (array) $value as $link ) {
					$lines[] = $link['label'] . ' | ' . $link['url'];
				}
				printf( '<textarea class="large-text code" rows="3" id="%1$s" name="%2$s" placeholder="%4$s">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( implode( "\n", $lines ) ), esc_attr( 'Home | ' . home_url( '/' ) ) );
				$note( __( 'One per line: a label, a vertical bar, and an address. With none, the blyg links to this site\'s home page.', 'soapbox-for-blygger' ) );
				break;

			case 'featured_image':
				$check( __( 'Lead each item with the post\'s featured image', 'soapbox-for-blygger' ) );
				$note( __( 'Unless the post already shows that image, or opens with an image of its own. Changing this changes what posts look like the next time each is saved.', 'soapbox-for-blygger' ) );
				break;

			case 'legacy_repair':
				$check( __( 'Treat two or more line breaks in a row as a paragraph break', 'soapbox-for-blygger' ) );
				$note( __( 'Posts written before the block editor often used doubled line breaks as paragraphs. Leave this on unless your posts use them deliberately.', 'soapbox-for-blygger' ) );
				break;

			case 'cache_lifetime':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( Caching::LIFETIMES as $seconds ) {
					$label = Caching::in_words( $seconds );
					/* translators: %s: a length of time, such as "5 mins" */
					$label = Caching::DEFAULT_LIFETIME === $seconds ? sprintf( __( '%s (recommended)', 'soapbox-for-blygger' ), $label ) : $label;
					printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $seconds, selected( Caching::offered( $value ), $seconds, false ), esc_html( $label ) );
				}
				echo '</select>';
				$note( __( 'How long readers and the caches in front of your site may keep a copy of the blyg\'s documents before asking again. Shorter, and a post you withdraw or edit stops being served in its old form sooner; longer, and fewer requests reach your site. Asking again is cheap either way: a document that has not changed is not sent twice. An address that has nothing yet is remembered as "not found" for the same time. Pinned versions never change and are not affected.', 'soapbox-for-blygger' ) );
				break;

			case 'webmention':
				$check( __( 'Accept Webmentions from other blygs', 'soapbox-for-blygger' ) );
				$note( __( 'When another blyg quotes, answers or forks one of your items, it can tell this site. To check that the claim is true, this site then fetches that blyg\'s page and its item document, and its manifest when its documents are not at the usual addresses: at most three documents per mention, from addresses the sender chose, never from a private network. Nothing fetched is stored or displayed; mentions appear under Tools, for administrators only. With this off, the site makes no outbound requests at all.', 'soapbox-for-blygger' ) );
				break;

			case 'delete_data':
				if ( Plugin::earlier_plugin_installed() ) {
					printf(
						'<label><input type="checkbox" id="%1$s" disabled> %2$s</label>',
						esc_attr( $id ),
						esc_html__( 'Delete all Soapbox for Blygger data when the plugin is deleted', 'soapbox-for-blygger' )
					);
					$note( __( 'Kept off while Soapbox Blyg, this plugin\'s earlier name, is still installed: the two share one record of what has been published, and deleting Soapbox Blyg with this on would delete it. Delete Soapbox Blyg from the Plugins screen first.', 'soapbox-for-blygger' ) );
					break;
				}
				$check( __( 'Delete all Soapbox for Blygger data when the plugin is deleted', 'soapbox-for-blygger' ) );
				$note( __( 'Off by default, on purpose. The ledger holds every id, version and pin you have published, and it cannot be rebuilt: deleting it breaks every address the blyg has ever served, and reinstalling would start again with new ids. Deactivating the plugin never deletes anything.', 'soapbox-for-blygger' ) );
				break;
		}
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$plugin = Plugin::instance();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Soapbox for Blygger', 'soapbox-for-blygger' ); ?></h1>

			<div class="notice notice-info inline">
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: protocol version, 2: URL of the protocol specification */
							__( 'This plugin publishes under the <a href="%2$s">Blygger protocol</a>, version %1$s, which is a draft: its wire format may change before 1.0, and this plugin will follow it. What does not change is the promise you make by publishing. Every address the blyg serves is meant to keep answering, and a pinned version is served forever. Soapbox for Blygger is an independent implementation and is not affiliated with the protocol\'s authors.', 'soapbox-for-blygger' ),
							esc_html( \SoapboxBlyg\Surface\Documents::PROTOCOL ),
							esc_url( 'https://blygger.org/spec/0.3/' )
						),
						array( 'a' => array( 'href' => true ) )
					);
					?>
				</p>
			</div>

			<?php if ( '' === (string) get_option( 'permalink_structure' ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'This site uses "Plain" permalinks, and a blyg cannot be served on them. Choose any other structure under Settings > Permalinks.', 'soapbox-for-blygger' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $plugin->registry->has( PostTypeSource::ORIGIN ) ) : ?>
				<p>
					<?php
					$origin = $plugin->registry->get( PostTypeSource::ORIGIN );
					printf(
						wp_kses(
							/* translators: 1: the blyg's address, 2: its manifest URL */
							__( 'Your blyg is at <code>%1$s</code>. <a href="%2$s">Manifest</a>', 'soapbox-for-blygger' ),
							array(
								'code' => array(),
								'a'    => array( 'href' => true ),
							)
						),
						esc_html( $origin->url() ),
						esc_url( $plugin->locations->manifest( $origin ) )
					);
					?>
					&middot; <a href="<?php echo esc_url( admin_url( 'tools.php?page=' . ToolsPage::PAGE ) ); ?>"><?php esc_html_e( 'Tools', 'soapbox-for-blygger' ); ?></a>
				</p>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				printf( '<input type="hidden" name="%s[_screen]" value="1">', esc_attr( Settings::OPTION ) );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
