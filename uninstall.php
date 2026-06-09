<?php
/**
 * Uninstall cleanup for plugin-owned data only.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'frm_square_entry_state');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'frm_square_audit_log');
delete_option('frm_square_hc_settings');
