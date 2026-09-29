<?php
/**
 * Registers `wp dbdelta-dryrun`. Loaded by WP-CLI as a package, or with --require=command.php.
 *
 * @package DbDeltaDryrun
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

require_once __DIR__ . '/src/Parser.php';
require_once __DIR__ . '/src/Diagnosis.php';
require_once __DIR__ . '/src/Report.php';
require_once __DIR__ . '/src/Command.php';

WP_CLI::add_command( 'dbdelta-dryrun', \DbDeltaDryrun\Command::class );
