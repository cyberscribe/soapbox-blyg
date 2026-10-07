<?php
/**
 * Tools.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Admin;

use SoapboxBlyg\Ledger\Schema;
use SoapboxBlyg\Ledger\Verifier;
use SoapboxBlyg\Mentions\Store;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Documents;
use SoapboxBlyg\Surface\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * Tools > Soapbox Blyg: what each blyg on the site holds, and the jobs that would otherwise need
 * a command line. The backfill and verify run as REST calls from this screen, in batches, so
 * they work on a site with no shell and do not depend on WP-Cron, which stalls on sites with
 * little traffic.
 */
class ToolsPage {

	const PAGE = 'soapbox-blyg-tools';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'after_plugin_row_' . plugin_basename( SOAPBOX_BLYG_FILE ), array( __CLASS__, 'plugin_row' ), 10, 2 );
	}

	/**
	 * Adds the screen under Tools.
	 */
	public static function menu(): void {
		add_management_page(
			__( 'Soapbox Blyg', 'soapbox-blyg' ),
			__( 'Soapbox Blyg', 'soapbox-blyg' ),
			Capabilities::MANAGE,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Loads the screen's script.
	 *
	 * @param string $hook The current admin page.
	 */
	public static function assets( $hook ): void {
		if ( 'tools_page_' . self::PAGE !== $hook ) {
			return;
		}
		self::enqueue( 'tools' );
	}

	/**
	 * Enqueues one of the built scripts with the data it needs.
	 *
	 * @param string $name admin or editor-panel.
	 * @param array  $data Extra data for the script.
	 */
	public static function enqueue( string $name, array $data = array() ): void {
		$asset_file = dirname( SOAPBOX_BLYG_FILE ) . '/build/' . $name . '.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset  = include $asset_file;
		$handle = SOAPBOX_BLYG_SLUG . '-' . $name;
		wp_enqueue_script( $handle, plugins_url( 'build/' . $name . '.js', SOAPBOX_BLYG_FILE ), $asset['dependencies'], $asset['version'], true );
		// The path is for the translations bundled with the plugin. A language pack from
		// translate.wordpress.org, in WP_LANG_DIR, is looked for first and wins.
		wp_set_script_translations( $handle, 'soapbox-blyg', dirname( SOAPBOX_BLYG_FILE ) . '/languages' );
		// A script's file name must not end in "min.js", "admin.js" included: WP-CLI's make-json
		// takes that for a minified file and names its translations for a script that does not exist.
		wp_add_inline_script(
			$handle,
			'window.soapboxBlyg = ' . wp_json_encode( array( 'path' => '/' . Locations::rest_namespace() . '/admin' ) + $data ) . ';',
			'before'
		);
	}

	/**
	 * Tells administrators when an origin was asked to move and was not moved.
	 */
	public static function notices(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$registry = Plugin::instance()->registry;
		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$where    = $screen ? (string) $screen->id : '';
		// These are said where someone has come to manage plugins or this one, and nowhere else:
		// a notice on every admin screen, of every site of a network, is a nag.
		$ours = in_array( $where, array( 'plugins', 'tools_page_' . self::PAGE, 'settings_page_' . SettingsPage::PAGE ), true );

		// The one thing said on every admin screen: the blyg has gone offline because its tables
		// are not as they must be. That is an error on the site, not a suggestion, and each
		// person can dismiss it once they have read it.
		$problem = Schema::blocked();
		$stamp   = Schema::problem_key();
		if ( '' !== $problem && isset( $_GET['soapbox_blyg_dismiss'] ) && check_admin_referer( 'soapbox_blyg_dismiss' ) ) {
			update_user_meta( get_current_user_id(), 'soapbox_blyg_dismissed_problem', $stamp );
		}
		if ( '' !== $problem && ( $ours || get_user_meta( get_current_user_id(), 'soapbox_blyg_dismissed_problem', true ) !== $stamp ) ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				'engine' === $problem
					? __( 'Soapbox Blyg is not publishing, and its blyg is offline: its database tables are not using the InnoDB storage engine, which it needs in order to record what is published reliably. Ask your host to convert the soapbox_blyg tables to InnoDB, then deactivate and activate the plugin.', 'soapbox-blyg' )
					: __( 'Soapbox Blyg is not publishing, and its blyg is offline: its database tables could not be brought up to date. It will try again; if this notice stays, deactivate and activate the plugin, and check that the database user may create and alter tables.', 'soapbox-blyg' )
			);
			if ( ! $ours ) {
				printf( ' <a href="%s">%s</a>', esc_url( wp_nonce_url( add_query_arg( 'soapbox_blyg_dismiss', '1' ), 'soapbox_blyg_dismiss' ) ), esc_html__( 'Dismiss', 'soapbox-blyg' ) );
			}
			echo '</p></div>';
		}
		if ( $ours && ! Settings::get( 'enabled' ) && $registry->is_locked( PostTypeSource::ORIGIN ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Soapbox Blyg: this site has a blyg that has published, and publishing is switched off, so every address it published is answering 404. Switch publishing back on in the settings to serve it again.', 'soapbox-blyg' );
			printf( ' <a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . SettingsPage::PAGE ) ), esc_html__( 'Open the settings', 'soapbox-blyg' ) );
			echo '</p></div>';
		}
		// Until the first-run setup is done the plugin publishes nothing, and says so.
		if ( in_array( $where, array( 'plugins', 'tools_page_' . self::PAGE ), true ) && ! Settings::get( 'setup' ) && ! $registry->is_locked( PostTypeSource::ORIGIN ) ) {
			echo '<div class="notice notice-info"><p>';
			echo esc_html__( 'Soapbox Blyg is active but not publishing. Choose where the blyg lives and how it is laid out, then switch publishing on.', 'soapbox-blyg' );
			printf( ' <a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . SettingsPage::PAGE ) ), esc_html__( 'Open the settings', 'soapbox-blyg' ) );
			echo '</p></div>';
		}
		foreach ( $registry->conflicts() as $key => $asked ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: origin key, 2: the path asked for, 3: the path it is locked to */
					__( 'Soapbox Blyg: the blyg "%1$s" is configured for %2$s, but it has already published at %3$s and is still served there. Published addresses are permanent; put the configuration back to %3$s.', 'soapbox-blyg' ),
					$key,
					$asked,
					$registry->get( $key )->path
				)
			);
			echo '</p></div>';
		}
	}

	/**
	 * A line under the plugin on the Plugins screen, shown while it is active and has published:
	 * what deactivating does. It has to be said before the click, because afterwards the plugin is
	 * not running to say anything.
	 *
	 * @param string $file   Plugin file.
	 * @param array  $plugin Plugin data.
	 */
	public static function plugin_row( $file, $plugin ): void {
		unset( $file, $plugin );
		$published = false;
		foreach ( Plugin::instance()->registry->all() as $origin ) {
			$published = $published || Plugin::instance()->registry->is_locked( $origin->key );
		}
		if ( ! $published ) {
			return;
		}
		$columns = get_current_screen() ? count( get_column_headers( get_current_screen() ) ) : 4;
		echo '<tr class="plugin-update-tr active"><td colspan="' . (int) $columns . '" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>';
		esc_html_e( 'This site has published a blyg. Deactivating this plugin keeps all of its data, but takes the blyg offline: every address it serves will answer 404 until the plugin is activated again.', 'soapbox-blyg' );
		echo '</p></div></td></tr>';
	}

	/**
	 * Every pinned version on the site, newest pin first.
	 *
	 * @return array[]
	 */
	private static function pins(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an admin screen reading the plugin's own tables.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT v.blyg_id, v.version, v.pinned_at, v.title, i.origin_key, i.source_key FROM %i v JOIN %i i ON i.blyg_id = v.blyg_id WHERE v.pinned = 1 ORDER BY v.pinned_at DESC LIMIT 200',
				Schema::table( 'versions' ),
				Schema::table( 'items' )
			),
			ARRAY_A
		);
	}

	/**
	 * A protocol timestamp in the site's date and time format.
	 *
	 * @param string|null $iso ISO 8601 UTC.
	 */
	private static function when( $iso ): string {
		return $iso ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( (string) $iso ) ) : '';
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$plugin  = Plugin::instance();
		$origins = $plugin->registry->all();
		?>
		<div class="wrap soapbox-blyg-tools">
			<h1><?php esc_html_e( 'Soapbox Blyg', 'soapbox-blyg' ); ?></h1>

			<?php if ( ! $origins ) : ?>
				<p>
					<?php esc_html_e( 'Publishing is switched off for this site, so there is no blyg to show.', 'soapbox-blyg' ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . SettingsPage::PAGE ) ); ?>"><?php esc_html_e( 'Settings', 'soapbox-blyg' ); ?></a>
				</p>
		</div>
				<?php
				return;
			endif;
			?>

			<h2><?php esc_html_e( 'Blygs on this site', 'soapbox-blyg' ); ?></h2>
			<table class="widefat striped" style="max-width:60rem">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Address', 'soapbox-blyg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Layout', 'soapbox-blyg' ); ?></th>
					<th scope="col"><?php echo esc_html_x( 'Items', 'column heading: how many items a blyg has', 'soapbox-blyg' ); ?></th>
					<th scope="col"><?php echo esc_html_x( 'Versions', 'column heading: how many versions a blyg has', 'soapbox-blyg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last updated', 'soapbox-blyg' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Documents', 'soapbox-blyg' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $origins as $origin ) :
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an admin screen reading the plugin's own tables.
					$versions = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i v JOIN %i i ON i.blyg_id = v.blyg_id WHERE i.origin_key = %s', Schema::table( 'versions' ), Schema::table( 'items' ), $origin->key ) );
					?>
					<tr>
						<td><code><?php echo esc_html( $origin->url() ); ?></code><br><span class="description"><?php echo esc_html( $origin->title ); ?></span></td>
						<td><?php echo esc_html( 'fixed' === $origin->layout ? __( 'Fixed paths', 'soapbox-blyg' ) : __( 'Templated', 'soapbox-blyg' ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $plugin->ledger->count_items( $origin->key ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $versions ) ); ?></td>
						<td><?php echo esc_html( self::when( $plugin->ledger->latest_updated( $origin->key ) ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( $plugin->locations->manifest( $origin ) ); ?>"><?php esc_html_e( 'Manifest', 'soapbox-blyg' ); ?></a> &middot;
							<a href="<?php echo esc_url( $plugin->locations->feed( $origin ) ); ?>"><?php esc_html_e( 'Feed', 'soapbox-blyg' ); ?></a> &middot;
							<a href="<?php echo esc_url( $plugin->locations->index( $origin ) ); ?>"><?php esc_html_e( 'Archive index', 'soapbox-blyg' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$owed  = $plugin->posts->owed( 1 );
			$error = $owed ? $owed[0] : null;
			if ( is_array( $error ) ) :
				?>
				<div class="notice notice-warning inline"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: post id, 2: date and time, 3: error message */
							__( 'Post %1$d was saved on %2$s but the blyg could not be brought into step with it: %3$s. The plugin keeps trying; saving the post again also retries.', 'soapbox-blyg' ),
							(int) $error['post'],
							self::when( $error['at'] ),
							(string) $error['message']
						)
					);
					?>
				</p></div>
			<?php endif; ?>

			<?php $publishing = $plugin->registry->has( PostTypeSource::ORIGIN ); ?>
			<?php if ( $publishing || Settings::get( 'setup' ) ) : ?>
				<?php $waiting = count( $plugin->posts->unpublished_ids() ); ?>
				<h2><?php esc_html_e( 'Backfill', 'soapbox-blyg' ); ?></h2>
				<p>
					<span data-soapbox-blyg-waiting>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of posts, already formatted for the locale (for example 1,234) */
							_n( '%s published post is not on the blyg yet.', '%s published posts are not on the blyg yet.', $waiting, 'soapbox-blyg' ),
							number_format_i18n( $waiting )
						)
					);
					?>
					</span>
					<?php esc_html_e( 'A post joins the blyg when it is next saved; the backfill adds the ones published before the plugin was switched on, each as version 1, dated when the post was published.', 'soapbox-blyg' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'A dry run renders every post and writes nothing, so you can read what would be published before it is. Publishing cannot be undone: an item can be withdrawn, but its address answers forever.', 'soapbox-blyg' ); ?>
				</p>
				<p>
					<button type="button" class="button" data-soapbox-blyg="backfill-dry" <?php disabled( 0, $waiting ); ?>><?php esc_html_e( 'Dry run', 'soapbox-blyg' ); ?></button>
					<?php if ( $publishing ) : ?>
						<button type="button" class="button button-primary" data-soapbox-blyg="backfill" <?php disabled( 0, $waiting ); ?>><?php esc_html_e( 'Run the backfill', 'soapbox-blyg' ); ?></button>
					<?php endif; ?>
				</p>
				<?php if ( ! $publishing ) : ?>
					<p class="description"><?php esc_html_e( 'Publishing is switched off, so only the dry run is available. Nothing is published by it, now or later. Switch publishing on in the settings when you have looked at what the dry run shows.', 'soapbox-blyg' ); ?></p>
				<?php endif; ?>
				<div data-soapbox-blyg-output="backfill" aria-live="polite"></div>
			<?php endif; ?>

			<h2><?php echo esc_html_x( 'Verify', 'section heading: checking the ledger', 'soapbox-blyg' ); ?></h2>
			<p>
				<?php esc_html_e( 'Recomputes the hash of every version ever published and checks that the ledger is complete. With render drift, it also compares the blyg with the site: posts that should be on it and are not, and posts that would publish a new version if saved now, which is worth knowing after a WordPress or plugin update.', 'soapbox-blyg' ); ?>
			</p>
			<?php
			$last = get_option( Verifier::RESULT_OPTION );
			if ( is_array( $last ) ) :
				?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							! empty( $last['passed'] )
								/* translators: %s: date and time */
								? __( 'Last verified %s: it passed.', 'soapbox-blyg' )
								/* translators: %s: date and time */
								: __( 'Last verified %s: it failed.', 'soapbox-blyg' ),
							self::when( $last['at'] )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<p>
				<button type="button" class="button" data-soapbox-blyg="verify"><?php esc_html_e( 'Verify the ledger', 'soapbox-blyg' ); ?></button>
				<button type="button" class="button" data-soapbox-blyg="verify-render"><?php esc_html_e( 'Verify with render drift', 'soapbox-blyg' ); ?></button>
			</p>
			<div data-soapbox-blyg-output="verify" aria-live="polite"></div>

			<h2><?php echo esc_html_x( 'Pins', 'section heading: the versions that have been pinned', 'soapbox-blyg' ); ?></h2>
			<?php $pins = self::pins(); ?>
			<?php if ( ! $pins ) : ?>
				<p><?php esc_html_e( 'Nothing is pinned. Pinning a version, from the post editor, promises to serve exactly that version at its own address forever.', 'soapbox-blyg' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:60rem">
					<thead><tr>
						<th scope="col"><?php echo esc_html_x( 'Item', 'column heading: an item of the blyg', 'soapbox-blyg' ); ?></th>
						<th scope="col"><?php echo esc_html_x( 'Version', 'column heading: a version number', 'soapbox-blyg' ); ?></th>
						<th scope="col"><?php echo esc_html_x( 'Pinned', 'column heading: the date a version was pinned', 'soapbox-blyg' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $pins as $pin ) : ?>
						<?php
						if ( ! $plugin->registry->has( $pin['origin_key'] ) ) {
							continue;
						}
						$pin_origin = $plugin->registry->get( $pin['origin_key'] );
						?>
						<tr>
							<td><?php echo esc_html( '' !== $pin['title'] ? $pin['title'] : $pin['source_key'] ); ?><br><code><?php echo esc_html( $pin['blyg_id'] ); ?></code></td>
							<td><a href="<?php echo esc_url( $plugin->locations->pin( $pin_origin, $pin['blyg_id'], (int) $pin['version'] ) ); ?>">v<?php echo (int) $pin['version']; ?></a></td>
							<td><?php echo esc_html( self::when( $pin['pinned_at'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( Documents::receives_mentions() ) : ?>
				<h2><?php esc_html_e( 'Mentions', 'soapbox-blyg' ); ?></h2>
				<p><?php esc_html_e( 'Other blygs that have told this site they quoted, answered or forked one of its items. A mention is verified when the other blyg\'s own document says so; nothing here is shown to visitors.', 'soapbox-blyg' ); ?></p>
				<?php $mentions = $plugin->mentions->query( array( 'limit' => 100 ) ); ?>
				<?php if ( ! $mentions ) : ?>
					<p><?php esc_html_e( 'No mentions yet.', 'soapbox-blyg' ); ?></p>
				<?php else : ?>
					<?php
					$statuses  = array(
						Store::PENDING  => __( 'Waiting to be verified', 'soapbox-blyg' ),
						Store::VERIFIED => __( 'Verified', 'soapbox-blyg' ),
						Store::FAILED   => __( 'Not verified', 'soapbox-blyg' ),
						Store::GONE     => __( 'Withdrawn by its author', 'soapbox-blyg' ),
					);
					$relations = array(
						/* translators: %s: the address of one of this site's items, as a link. Another publication's item is a reply to it. */
						'stub'         => __( 'Answers %s', 'soapbox-blyg' ),
						/* translators: %s: the address of one of this site's items, as a link. Another publication's item quotes it. */
						'transclusion' => __( 'Quotes %s', 'soapbox-blyg' ),
						/* translators: %s: the address of one of this site's items, as a link. Another publication's item is a fork of it. */
						'fork'         => __( 'Forked from %s', 'soapbox-blyg' ),
					);
					?>
					<table class="widefat striped" style="max-width:60rem">
						<thead><tr>
							<th scope="col"><?php echo esc_html_x( 'From', 'column heading: the publication a mention came from', 'soapbox-blyg' ); ?></th>
							<th scope="col"><?php echo esc_html_x( 'About', 'column heading: the item a mention refers to', 'soapbox-blyg' ); ?></th>
							<th scope="col"><?php echo esc_html_x( 'Status', 'column heading: whether a mention has been verified', 'soapbox-blyg' ); ?></th>
							<th scope="col"><?php echo esc_html_x( 'Received', 'column heading: when a mention arrived', 'soapbox-blyg' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $mentions as $mention ) : ?>
							<?php
							$author = $mention['source_author'] ? json_decode( $mention['source_author'], true ) : null;
							$from   = $mention['source_page'] ? $mention['source_page'] : $mention['source_url'];
							?>
							<tr>
								<td>
									<?php // The origin is what was verified. The page is the sender's word, kept only under that origin. ?>
									<?php if ( ! empty( $mention['source_origin'] ) ) : ?>
										<strong><?php echo esc_html( $mention['source_origin'] ); ?></strong><br>
									<?php endif; ?>
									<a href="<?php echo esc_url( $from ); ?>" rel="noopener noreferrer nofollow" target="_blank"><?php echo esc_html( $from ); ?></a>
									<?php if ( is_array( $author ) && ! empty( $author['name'] ) && is_string( $author['name'] ) ) : ?>
										<br><span class="description"><?php echo esc_html( $author['name'] ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									$about = sprintf( '<a href="%s">%s</a>', esc_url( $mention['target_url'] ), esc_html( (string) wp_parse_url( $mention['target_url'], PHP_URL_PATH ) ) );
									// The sentence is the translator's, with the link placed where their language wants it.
									echo wp_kses( sprintf( esc_html( isset( $relations[ $mention['relation'] ] ) ? $relations[ $mention['relation'] ] : '%s' ), $about ), array( 'a' => array( 'href' => true ) ) );
									?>
								</td>
								<td><?php echo esc_html( isset( $statuses[ $mention['status'] ] ) ? $statuses[ $mention['status'] ] : $mention['status'] ); ?></td>
								<td><?php echo esc_html( self::when( $mention['received_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
