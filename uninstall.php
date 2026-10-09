<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * By default it removes nothing. The ledger is the record of every id, version and pin the site
 * has published; it cannot be rebuilt, and without it every address the blyg has served is broken
 * for good. So the data stays unless the site owner ticked "Delete all Soapbox for Blygger data
 * when the plugin is deleted" in the settings, where that consequence is spelled out.
 *
 * On a network, each site decides for itself.
 *
 * @package SoapboxBlyg
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes one site's data, if that site opted in.
 */
function soapbox_blyg_uninstall_site() {
	global $wpdb;

	// The plugin was first released as "Soapbox Blyg", in the folder soapbox-blyg, and that
	// plugin uses the same tables, options and scheduled hooks. While it is installed, none of
	// them is this plugin's to remove, whatever the setting says.
	if ( file_exists( WP_PLUGIN_DIR . '/soapbox-blyg/soapbox-blyg.php' ) ) {
		return;
	}

	// Housekeeping that is not the site's data goes whatever was chosen: markers the plugin wrote
	// for itself on every site of a network, scheduled work, and an option earlier versions used.
	delete_option( 'soapbox_blyg_activation_seen' );
	delete_option( 'soapbox_blyg_viewable_types' );
	delete_option( 'soapbox_blyg_plugin_version' );
	delete_metadata( 'user', 0, 'soapbox_blyg_dismissed_problem', '', true );
	delete_option( 'soapbox_blyg_last_error' );
	delete_transient( 'soapbox_blyg_stale_posts' );
	foreach ( array( 'soapbox_blyg_sweep_mentions', 'soapbox_blyg_sweep_mentions_more', 'soapbox_blyg_verify_mention', 'soapbox_blyg_repair', 'soapbox_blyg_reconcile_run', 'soapbox_blyg_purge_again' ) as $soapbox_blyg_hook ) {
		wp_unschedule_hook( $soapbox_blyg_hook );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $soapbox_blyg_hook );
		}
	}

	$settings = get_option( 'soapbox_blyg_settings' );
	if ( ! is_array( $settings ) || empty( $settings['delete_data'] ) ) {
		return;
	}

	foreach ( array( 'items', 'versions', 'mentions', 'repairs', 'counters' ) as $soapbox_blyg_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables, at the owner's explicit request.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'soapbox_blyg_' . $soapbox_blyg_table ) );
	}

	foreach (
		array(
			'soapbox_blyg_settings',
			'soapbox_blyg_settings_changed',
			'soapbox_blyg_db_version',
			'soapbox_blyg_origin_locks',
			'soapbox_blyg_registered',
			'soapbox_blyg_rules_hash',
			'soapbox_blyg_reconcile',
			'soapbox_blyg_repairs_pending',
			'soapbox_blyg_settings_version',
			'soapbox_blyg_schema_problem',
			'soapbox_blyg_last_verify',
		) as $soapbox_blyg_option
	) {
		delete_option( $soapbox_blyg_option );
	}
}

if ( is_multisite() ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- loop variable in a file with no functions scope.
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $soapbox_blyg_site_id ) {
		switch_to_blog( (int) $soapbox_blyg_site_id );
		soapbox_blyg_uninstall_site();
		restore_current_blog();
	}
	delete_site_option( 'soapbox_blyg_network_activated' );
} else {
	soapbox_blyg_uninstall_site();
}
