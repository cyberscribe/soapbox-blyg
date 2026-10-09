<?php
/**
 * Admin REST routes.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Rest;

use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Admin\Capabilities;
use SoapboxBlyg\Admin\SiteHealth;
use SoapboxBlyg\Ledger\Verifier;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Surface\Locations;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The routes the admin screens and the editor call, under soapbox-blyg/v1/admin. Every route has
 * a permission callback, and authentication is WordPress's own cookie-and-nonce check for REST,
 * so there are no admin-post.php handlers and no nonces of the plugin's own.
 *
 * These are separate from the public document routes in Surface\RestDocuments: those return a
 * document's bytes to anyone, and these return ordinary REST responses to people who are logged
 * in and allowed.
 */
class AdminController {

	/**
	 * Posts handled per backfill request. Small enough to finish well inside a default PHP time
	 * limit on a slow host; the Tools screen keeps calling until none remain.
	 */
	const BATCH = 20;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Permission callback for the management routes.
	 */
	public static function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Registers the routes.
	 */
	public static function routes(): void {
		$namespace = Locations::rest_namespace();
		$post_arg  = array(
			'type'     => 'integer',
			'required' => true,
			'minimum'  => 1,
		);

		register_rest_route(
			$namespace,
			'/admin/item',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'item' ),
				'permission_callback' => static function ( WP_REST_Request $request ) {
					return current_user_can( 'edit_post', (int) $request['post'] );
				},
				'args'                => array( 'post' => $post_arg ),
			)
		);
		register_rest_route(
			$namespace,
			'/admin/pin',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'pin' ),
				'permission_callback' => static function ( WP_REST_Request $request ) {
					return current_user_can( Capabilities::PIN, (int) $request['post'] );
				},
				'args'                => array(
					'post'    => $post_arg,
					'version' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/admin/backfill',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'backfill' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'offset'  => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/admin/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'verify' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'render' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/admin/health/(?P<test>reachable|edge-cache)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function ( WP_REST_Request $request ) {
					return rest_ensure_response( 'reachable' === $request['test'] ? SiteHealth::test_reachable() : SiteHealth::test_edge_cache() );
				},
				'permission_callback' => static function () {
					return current_user_can( 'view_site_health_checks' );
				},
			)
		);
	}

	/**
	 * What the editor panel shows for one post: its item, its changelog, and what may be pinned.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function item( WP_REST_Request $request ) {
		return rest_ensure_response( self::item_data( (int) $request['post'] ) );
	}

	/**
	 * The editor panel's data for one post.
	 *
	 * @param int $post_id Post id.
	 */
	public static function item_data( int $post_id ): array {
		$plugin = Plugin::instance();
		$post   = get_post( $post_id );
		$data   = array(
			'publishes' => $post && $plugin->registry->has( PostTypeSource::ORIGIN ) && PostTypeSource::supports( $post->post_type ),
			'item'      => null,
			'can_pin'   => current_user_can( Capabilities::PIN, $post_id ),
		);
		if ( ! $data['publishes'] ) {
			return $data;
		}
		$status = $plugin->ledger->status( PostTypeSource::ORIGIN, PostTypeSource::key( $post_id ) );
		if ( ! $status ) {
			return $data;
		}
		$origin    = $plugin->registry->get( PostTypeSource::ORIGIN );
		$changelog = array();
		// A withdrawn item has nothing that may be pinned: pinning an old version would put what
		// the author took down back in public, for good.
		$withdrawn = 'withdrawn' === $status['kind'];
		foreach ( $plugin->ledger->changelog_rows( $status['id'] ) as $row ) {
			$version     = (int) $row['version'];
			$pinned      = (bool) (int) $row['pinned'];
			$changelog[] = array(
				'version'   => $version,
				'at'        => $row['at'],
				'kind'      => $row['kind'],
				'note'      => $row['note'],
				'pinned'    => $pinned,
				'pin_url'   => $pinned ? $plugin->locations->pin( $origin, $status['id'], $version ) : null,
				'pinned_by' => $pinned && ! empty( $row['pinned_by'] ) && get_userdata( (int) $row['pinned_by'] ) ? get_userdata( (int) $row['pinned_by'] )->display_name : null,
				'pinnable'  => ! $pinned && ! $withdrawn && 'withdrawn' !== $row['kind'],
			);
		}
		$data['item'] = array(
			'id'        => $status['id'],
			'version'   => $status['version'],
			'kind'      => $status['kind'],
			'url'       => $plugin->locations->item( $origin, $status['id'] ),
			'changelog' => $changelog,
			'mentions'  => \SoapboxBlyg\Surface\Documents::receives_mentions() ? $plugin->mentions->verified_count( $status['id'] ) : null,
		);
		return $data;
	}

	/**
	 * Pins one version of a post's item.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function pin( WP_REST_Request $request ) {
		$post_id = (int) $request['post'];
		$status  = Plugin::instance()->ledger->status( PostTypeSource::ORIGIN, PostTypeSource::key( $post_id ) );
		if ( ! $status ) {
			return new WP_Error( 'soapbox_blyg_not_published', __( 'This post is not on the blyg, so it has nothing to pin.', 'soapbox-for-blygger' ), array( 'status' => 404 ) );
		}
		try {
			soapbox_blyg_pin( $status['id'], (int) $request['version'] );
		} catch ( Throwable $e ) {
			$reasons = array(
				Ledger::PIN_NO_VERSION => __( 'That version does not exist.', 'soapbox-for-blygger' ),
				Ledger::PIN_ENDCAP     => __( 'That version records the post being withdrawn. It has no content, and cannot be pinned.', 'soapbox-for-blygger' ),
				Ledger::PIN_WITHDRAWN  => __( 'This post is withdrawn from the blyg, so no version of it can be pinned now.', 'soapbox-for-blygger' ),
				Ledger::PIN_NOT_PUBLIC => __( 'This post is not public now, so no version of it can be pinned.', 'soapbox-for-blygger' ),
				Ledger::PIN_CHANGED    => __( 'This post has changed since the blyg last took it in, so what would be pinned is not what it says now. Save the post, then pin. If its post type no longer publishes, its later changes are not on the blyg and it cannot be pinned.', 'soapbox-for-blygger' ),
				Ledger::PIN_ALREADY    => __( 'That version is already pinned.', 'soapbox-for-blygger' ),
			);
			$message = isset( $reasons[ $e->getCode() ] ) ? $reasons[ $e->getCode() ] : __( 'That version could not be pinned. Try again in a moment.', 'soapbox-for-blygger' );
			return new WP_Error( 'soapbox_blyg_not_pinned', $message, array( 'status' => 409 ) );
		}
		return rest_ensure_response( self::item_data( $post_id ) );
	}

	/**
	 * Runs one batch of the backfill. A real run gives v1 to the next posts that have no item; a
	 * dry run renders them and reports what each would look like, without writing.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function backfill( WP_REST_Request $request ) {
		$plugin = Plugin::instance();
		$dry    = (bool) $request['dry_run'];
		// A dry run writes nothing, so it does not need publishing to be on: only the address to
		// have been chosen, since a post's links are resolved against it. That is the order to do
		// things in: look at what would be published, and then switch publishing on.
		if ( ! $plugin->registry->has( PostTypeSource::ORIGIN ) && ! ( $dry && \SoapboxBlyg\Settings::get( 'setup' ) ) ) {
			return new WP_Error( 'soapbox_blyg_disabled', __( 'Publishing is switched off for this site.', 'soapbox-for-blygger' ), array( 'status' => 409 ) );
		}
		$offset  = $dry ? (int) $request['offset'] : 0; // A real run consumes its own queue; a dry run has to step through it.
		$ids     = $plugin->posts->unpublished_ids();
		$total   = count( $ids );
		$rows    = array();
		$done    = 0;
		$created = 0;
		$failed  = array();

		foreach ( array_slice( $ids, $offset, self::BATCH ) as $id ) {
			$post = get_post( $id );
			try {
				if ( $dry ) {
					$build = $plugin->posts->build( $post );
					if ( null !== $build['snapshot'] ) {
						$flags = array();
						if ( $build['featured_added'] ) {
							$flags[] = 'featured-only';
						}
						if ( $build['embed_links'] > 0 || $build['stats']['embeds'] > 0 ) {
							$flags[] = 'embed-link';
						}
						if ( $build['stats']['breaks'] > 10 ) {
							$flags[] = 'br-heavy';
						}
						if ( $build['stats']['relative_src'] > 0 ) {
							$flags[] = 'relative-src';
						}
						if ( $build['body_empty'] ) {
							$flags[] = 'empty-body';
						}
						$rows[] = array(
							'post'  => $id,
							'slug'  => PostTypeSource::dump_slug( $post ),
							'title' => $build['snapshot']['title'],
							'chars' => mb_strlen( $build['snapshot']['content_md'] ),
							'media' => count( $build['snapshot']['media'] ),
							'flags' => $flags,
							'md'    => $build['snapshot']['content_md'],
							'html'  => $build['snapshot']['content_html'],
						);
					}
				} else {
					// The same path as a save and as the command line: the post is read again
					// from the database, and is not published if it changes while it renders.
					$result = $plugin->posts->backfill_one( $post );
					if ( 'created' === $result['event'] ) {
						++$created;
					} else {
						$failed[] = array(
							'post'    => $id,
							'message' => __( 'The post produced no item.', 'soapbox-for-blygger' ),
						);
					}
				}
				++$done;
			} catch ( Throwable $e ) {
				$failed[] = array(
					'post'    => $id,
					'message' => preg_replace( '/^soapbox-blyg: /', '', $e->getMessage() ),
				);
			}
		}

		$next      = $offset + self::BATCH;
		$remaining = $dry ? max( 0, $total - $next ) : count( $plugin->posts->unpublished_ids() );
		// A real run takes its next batch from the posts that still have no item. If a batch created
		// nothing, the posts at the head of that list cannot publish, and asking again would only
		// meet them again: the run reports them and stops.
		$stuck = ! $dry && 0 === $created && $remaining > 0;
		return rest_ensure_response(
			array(
				'dry_run'   => $dry,
				'total'     => $total,
				'processed' => $done,
				'created'   => $created,
				'failed'    => $failed,
				'remaining' => $stuck ? 0 : $remaining,
				'stuck'     => $stuck ? $remaining : 0,
				'offset'    => $next,
				'rows'      => $rows,
			)
		);
	}

	/**
	 * Runs the integrity check, optionally with render drift.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function verify( WP_REST_Request $request ) {
		$plugin   = Plugin::instance();
		$verifier = new Verifier();
		$render   = (bool) $request['render'] && $plugin->registry->has( PostTypeSource::ORIGIN );
		$passed   = $verifier->run( null, $render ? $plugin->posts : null );
		return rest_ensure_response(
			array(
				'passed'   => $passed,
				'problems' => $verifier->problems,
				'warnings' => $verifier->warnings,
				'log'      => $verifier->log,
			)
		);
	}
}
