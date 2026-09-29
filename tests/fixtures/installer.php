<?php
/**
 * Synthetic installer for the --from test: calls dbDelta() the way plugins do.
 *
 * @package DbDeltaDryrun
 */

/**
 * Creates the test table with dbDelta().
 */
function acme_dryrun_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset_collate = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$wpdb->prefix}acme_from (
  id int unsigned NOT NULL AUTO_INCREMENT,
  label varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY  (id)
) {$charset_collate};"
	);
}
