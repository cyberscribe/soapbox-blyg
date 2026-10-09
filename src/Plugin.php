<?php
/**
 * Wiring.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg;

use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Ledger\Schema;
use SoapboxBlyg\Ledger\Validator;
use SoapboxBlyg\Mentions\Receiver;
use SoapboxBlyg\Mentions\Store;
use SoapboxBlyg\Mentions\Verifier as MentionVerifier;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Origins\Registry;
use SoapboxBlyg\Surface\Documents;
use SoapboxBlyg\Surface\ItemPage;
use SoapboxBlyg\Surface\Locations;
use SoapboxBlyg\Surface\RestDocuments;
use SoapboxBlyg\Surface\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the plugin's services and attaches them to WordPress. The services are plain objects
 * held here; there is no container, because a plugin this size does not need one.
 */
final class Plugin {

	/**
	 * The plugin version this site last ran, so that a new one is noticed.
	 */
	const VERSION_OPTION = 'soapbox_blyg_plugin_version';

	/**
	 * The one instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Origin registry.
	 *
	 * @var Registry
	 */
	public $registry;

	/**
	 * The ledger.
	 *
	 * @var Ledger
	 */
	public $ledger;

	/**
	 * Where documents live.
	 *
	 * @var Locations
	 */
	public $locations;

	/**
	 * The served documents.
	 *
	 * @var Documents
	 */
	public $documents;

	/**
	 * The router.
	 *
	 * @var Router
	 */
	public $router;

	/**
	 * The posts source.
	 *
	 * @var PostTypeSource
	 */
	public $posts;

	/**
	 * Announces the addresses a request changed, for caches.
	 *
	 * @var Integrations\Purge
	 */
	public $purge;

	/**
	 * The Webmention receiver.
	 *
	 * @var Receiver
	 */
	public $receiver;

	/**
	 * Stored mentions.
	 *
	 * @var Store
	 */
	public $mentions;

	/**
	 * The instance, built on first use.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Builds the services. Nothing here touches the database or registers a hook.
	 */
	private function __construct() {
		$this->registry  = new Registry();
		$this->ledger    = new Ledger( $this->registry );
		$this->locations = new Locations( $this->registry, $this->ledger );
		$this->documents = new Documents( $this->registry, $this->ledger, $this->locations );
		$this->mentions  = new Store();
		$this->receiver  = new Receiver( $this->mentions, $this->locations );
		$this->posts     = new PostTypeSource( $this->ledger );
		$this->purge     = new Integrations\Purge( $this->registry, $this->locations, $this->ledger );
	}

	/**
	 * Attaches everything to WordPress. Runs once, on `plugins_loaded`.
	 *
	 * Once, whoever asks: where the plugin under its earlier name is active too and is loaded
	 * after this one, its main file calls this as well, and finds this class.
	 */
	public static function boot(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;
		$plugin = self::instance();

		// On every request, before anything else of the plugin's: an update can replace the files
		// without anyone visiting the admin, and the next thing to happen may be a scheduled post
		// going out or a reader's request. It is one autoloaded option read when nothing is due.
		add_action( 'init', array( __CLASS__, 'upgrade' ), 0 );
		add_action( 'init', array( __CLASS__, 'keep_data_beside_the_earlier_plugin' ), 0 );
		add_action( 'admin_notices', array( __CLASS__, 'earlier_plugin_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'earlier_plugin_notice' ) );
		add_action( 'wp_initialize_site', array( __CLASS__, 'install_on_new_site' ), 900 );
		add_filter( 'wpmu_drop_tables', array( Schema::class, 'drop_with_site' ) );

		// Early on init, so code that saves a post later in init finds the origins registered.
		add_action( 'init', array( $plugin, 'register_origins' ), 1 );

		$pages          = new ItemPage( $plugin->registry, $plugin->ledger, $plugin->locations );
		$plugin->router = new Router( $plugin->registry, $plugin->documents, $pages, $plugin->receiver );

		$plugin->posts->register();
		$plugin->purge->register();
		// The cache plugins that can be told an address changed. Each does nothing when its plugin is absent.
		Integrations\Breeze::register();
		Integrations\W3TotalCache::register();
		Integrations\SiteGround::register();
		$plugin->router->register();
		$pages->register();
		( new RestDocuments( $plugin->registry, $plugin->documents, $plugin->router ) )->register();
		( new Discovery( $plugin->registry, $plugin->ledger, $plugin->locations ) )->register();

		// Mention verification runs from the queue only: never while a page is being served.
		$verifier = new MentionVerifier( $plugin->mentions, $plugin->registry, $plugin->ledger );
		add_action( Receiver::VERIFY_HOOK, array( $verifier, 'verify' ) );
		add_action( Receiver::SWEEP_HOOK, array( $verifier, 'sweep' ) );
		add_action( Receiver::SWEEP_HOOK . '_more', array( $verifier, 'sweep' ) );
		add_action(
			'init',
			static function () {
				Receiver::sync_sweep( Documents::receives_mentions() && (bool) Settings::get( 'enabled' ) );
			},
			30
		);

		add_action( 'update_option_' . Settings::OPTION, array( Settings::class, 'touch' ) );
		add_action( 'add_option_' . Settings::OPTION, array( Settings::class, 'touch' ) );

		Admin\Capabilities::register();
		Rest\AdminController::register();
		Admin\SettingsPage::register();
		Admin\SiteHealth::register();
		if ( is_admin() ) {
			Admin\ToolsPage::register();
			Admin\EditorPanel::register();
			add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );
		}

		Validator::$on_warning = static function ( string $message ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				\WP_CLI::warning( $message );
			}
		};

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'blyg', Cli\Command::class );
		}
	}

	/**
	 * Lets sources register their origins, on a site where publishing is switched on. A site with
	 * it off registers nothing, so it serves no surface and advertises nothing.
	 */
	public function register_origins(): void {
		// A ledger whose tables are not as they should be is not written to, and so not served from.
		if ( ! Settings::get( 'enabled' ) || '' !== Schema::blocked() ) {
			return;
		}
		/**
		 * Fires on `init` for sources to register their origins with
		 * soapbox_blyg_register_origin().
		 */
		do_action( 'soapbox_blyg_register_origins' );
	}

	/**
	 * Whether the plugin as it was first released, "Soapbox Blyg" in the folder soapbox-blyg, is
	 * still installed beside this one. Active or not: it is deleting it that matters.
	 */
	public static function earlier_plugin_installed(): bool {
		$earlier = WP_PLUGIN_DIR . '/' . SOAPBOX_BLYG_SLUG . '/' . SOAPBOX_BLYG_SLUG . '.php';
		return SOAPBOX_BLYG_FILE !== $earlier && file_exists( $earlier );
	}

	/**
	 * Holds "delete all data when the plugin is deleted" off while the earlier plugin is installed.
	 *
	 * The two share one ledger and one set of options. Deleting the earlier plugin runs the
	 * earlier plugin's uninstall, which reads that setting and, finding it on, drops the tables
	 * this plugin is serving from: every id and every address the site has published. Nothing in
	 * this plugin runs at that moment, so the setting is switched off beforehand, here, and the
	 * settings screen will not switch it on again until the earlier plugin has gone.
	 *
	 * The file is looked for only when the setting is on, so an ordinary request reads nothing extra.
	 */
	public static function keep_data_beside_the_earlier_plugin(): void {
		$settings = get_option( Settings::OPTION );
		if ( is_array( $settings ) && ! empty( $settings['delete_data'] ) && self::earlier_plugin_installed() ) {
			$settings['delete_data'] = false;
			update_option( Settings::OPTION, $settings );
		}
	}

	/**
	 * Says, on the Plugins screen and the plugin's own, that the earlier plugin can go and what
	 * is being held off until it has.
	 */
	public static function earlier_plugin_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'activate_plugins' ) || ! self::earlier_plugin_installed() ) {
			return;
		}
		if ( ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) && false === strpos( (string) $screen->id, SOAPBOX_BLYG_SLUG ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Soapbox Blyg, the earlier name of Soapbox for Blygger, is still installed. It can be deleted: this plugin has taken over the same blyg, at the same addresses. Until it has gone, "Delete all data when the plugin is deleted" is kept switched off, because deleting Soapbox Blyg with that setting on would delete the record of everything this site has published.', 'soapbox-for-blygger' ) . '</p></div>';
	}

	/**
	 * Brings the schema and the stored settings up to this version of the plugin.
	 */
	public static function upgrade(): void {
		Schema::maybe_install();
		// Every document names the plugin's version in its generator, so a new version is new
		// bytes at every address with no ledger event to say so. A site that ran a release from
		// before this was recorded counts as changed, once. Only if the record was written: where
		// it cannot be (an object cache that goes on answering the old version), every request
		// would purge everything instead.
		if ( SOAPBOX_BLYG_VERSION !== get_option( self::VERSION_OPTION ) && update_option( self::VERSION_OPTION, SOAPBOX_BLYG_VERSION, true ) ) {
			self::instance()->purge->everything_changed();
		}
		if ( (int) get_option( Settings::MEANING_OPTION, 0 ) < Settings::MEANING ) {
			$plugin = self::instance();
			$known  = false !== get_option( Schema::VERSION_OPTION );
			Settings::migrate(
				// As registration reads it: recorded, or put back from the origin's first item.
				$plugin->registry->lock_of( PostTypeSource::ORIGIN ),
				$known && $plugin->ledger->count_items( PostTypeSource::ORIGIN ) > 0
			);
		}
	}

	/**
	 * Activation: create the tables. The rewrite rules are flushed by Router on the next request,
	 * since `init` has already run by the time a plugin is activated.
	 *
	 * @param bool $network_wide Whether the plugin is being activated for a whole network.
	 */
	public static function activate( $network_wide ): void {
		// Without these the plugin would activate and then fail at the first save.
		$missing = array_filter(
			array( 'dom', 'mbstring' ),
			static function ( $extension ) {
				return ! extension_loaded( $extension );
			}
		);
		if ( $missing ) {
			/* translators: %s: a list of PHP extension names */
			self::refuse_activation( sprintf( __( 'Soapbox for Blygger needs the PHP extensions %s, which this server does not have. Ask your host to enable them.', 'soapbox-for-blygger' ), implode( ', ', $missing ) ) );
		}
		if ( is_multisite() && $network_wide ) {
			// Each site creates its tables when its admin is first visited, which is also where a
			// site has to go to switch publishing on. A site that already has a ledger asks for a
			// reconcile on its own next request: see PostTypeSource::notice_network_activation().
			update_site_option( PostTypeSource::NETWORK_ACTIVATED, (string) microtime( true ) );
			return;
		}
		update_option( Schema::PROBLEM_OPTION, '', true ); // Activating is asking for it to be tried again.
		if ( ! Schema::install() && 'engine' === Schema::$problem ) {
			self::refuse_activation( __( 'Soapbox for Blygger keeps its record of what was published in database tables that must use the InnoDB storage engine, and this database created them with another. Ask your host to make InnoDB the default engine, or convert the soapbox_blyg tables to InnoDB, then activate again.', 'soapbox-for-blygger' ) );
		}
		// Posts may have been unpublished while the plugin was inactive.
		PostTypeSource::request_reconcile();
	}

	/**
	 * Stops an activation, saying why, in the way WordPress shows on the Plugins screen.
	 *
	 * @param string $message Why the plugin cannot be activated here.
	 */
	private static function refuse_activation( string $message ): void {
		deactivate_plugins( plugin_basename( SOAPBOX_BLYG_FILE ) );
		wp_die( esc_html( $message ), esc_html__( 'Soapbox for Blygger could not be activated', 'soapbox-for-blygger' ), array( 'back_link' => true ) );
	}

	/**
	 * Deactivation removes the rewrite rules and nothing else. The ledger stays: the URLs it
	 * answers are promises, and reactivating must find them intact.
	 */
	public static function deactivate(): void {
		Router::forget();
		foreach ( array( Receiver::SWEEP_HOOK, Receiver::SWEEP_HOOK . '_more', Receiver::VERIFY_HOOK, PostTypeSource::REPAIR_HOOK, PostTypeSource::RECONCILE_HOOK, Integrations\Purge::AGAIN_HOOK ) as $hook ) {
			wp_unschedule_hook( $hook );
			// Verification is queued with Action Scheduler where another plugin provides it.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
		}
	}

	/**
	 * Suggests text for the site's privacy policy. The plugin collects nothing about visitors; what
	 * it stores about other people is what they published about themselves.
	 */
	public static function privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'This site publishes its posts as a blyg, using the Soapbox for Blygger plugin. Each published post is stored as a series of versions, with the author\'s display name as it was when that version was published. These versions stay available at their published addresses after the post is edited, and a withdrawn post\'s address continues to answer, with no content.', 'soapbox-for-blygger' ) . '</p>';
		$text .= '<p>' . esc_html__( 'The plugin sets no cookies and collects no information about visitors to this site.', 'soapbox-for-blygger' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If the site accepts Webmentions: when another publication tells this site that it has quoted or responded to something here, this site fetches that publication\'s page to confirm it, and records the address of that page, the name its author published there, and the time. It does not store the content of the page, and these records are visible to this site\'s administrators only. To limit how often one sender can do this, it also keeps a scrambled form of the network address the notification came from, which cannot be turned back into the address.', 'soapbox-for-blygger' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When a signed-in user pins a version of a post, the site records which user did so.', 'soapbox-for-blygger' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Soapbox for Blygger', 'soapbox-for-blygger' ), wp_kses_post( $text ) );
	}

	/**
	 * Creates the tables for a site added to a network where the plugin is network-active.
	 *
	 * @param \WP_Site $site The new site.
	 */
	public static function install_on_new_site( $site ): void {
		if ( ! Settings::is_network_active() ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		Schema::install();
		restore_current_blog();
	}
}
