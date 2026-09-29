<?php
/**
 * Unit tests for the parts that need no database: php tests/run.php
 *
 * @package DbDeltaDryrun
 */

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Diagnosis.php';
require __DIR__ . '/../src/Report.php';

use DbDeltaDryrun\Diagnosis;
use DbDeltaDryrun\Parser;
use DbDeltaDryrun\Report;

$failures = 0;
$count    = 0;

/**
 * Runs one test.
 *
 * @param string   $name Test name.
 * @param callable $fn   Test body; throws on failure.
 */
function test( $name, $fn ) {
	global $failures, $count;
	++$count;
	try {
		$fn();
		echo "ok {$count} - {$name}\n";
	} catch ( Throwable $e ) {
		++$failures;
		echo "not ok {$count} - {$name}\n  " . $e->getMessage() . "\n";
	}
}

/**
 * Fails unless the two values are identical.
 */
function same( $expected, $actual, $what = '' ) {
	if ( $expected !== $actual ) {
		throw new Exception( ( $what ? "{$what}: " : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

$create = "CREATE TABLE wp_acme (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `Name` varchar(191) NOT NULL DEFAULT '',\n  price decimal(10, 2) NOT NULL DEFAULT '0',\n  PRIMARY KEY  (id),\n  KEY name (Name(20)),\n  KEY email (email) USING BTREE\n) DEFAULT CHARSET=utf8mb4";

/** Context for Diagnosis::explain(). */
function context( $column, $create, $mariadb = true, $indexes = array() ) {
	return array(
		'table'   => '{prefix}acme',
		'create'  => $create,
		'column'  => $column,
		'indexes' => $indexes,
		'mariadb' => $mariadb,
		'version' => $mariadb ? '11.8.6' : '8.4.6',
	);
}

test(
	'statements split on semicolons and drop comment lines',
	function () {
		same( array( 'CREATE TABLE a (id int)', 'INSERT INTO a VALUES (1)' ), Parser::statements( "-- note\nCREATE TABLE a (id int);\nINSERT INTO a VALUES (1);\n" ) );
	}
);

test(
	'the table name is read as dbDelta() reads it, and as it really is',
	function () {
		same( 'wp_acme', Parser::table( 'CREATE TABLE `wp_acme` (id int)' ) );
		same( 'IF', Parser::table( 'CREATE TABLE IF NOT EXISTS wp_acme (id int)' ) );
		same( 'wp_acme', Parser::real_table( 'CREATE TABLE IF NOT EXISTS wp_acme (id int)' ) );
		same( '{prefix}acme', Parser::real_table( 'CREATE TABLE {prefix}acme (id int)' ) );
		same( null, Parser::real_table( 'INSERT INTO wp_acme VALUES (1)' ) );
	}
);

test(
	'field and index lines are separated the way dbDelta() separates them',
	function () use ( $create ) {
		$lines = Parser::lines( $create );
		same( array( 'id', 'name', 'price' ), array_keys( $lines['fields'] ) );
		same( 3, count( $lines['indexes'] ) );
		same( 'decimal(10,', Parser::field_type( 'price', $lines['fields']['price'] ) );
		same( 'bigint(20) unsigned', Parser::field_type( 'id', $lines['fields']['id'] ) );
		same( '0', Parser::field_default( $lines['fields']['price'] ) );
		same( null, Parser::field_default( 'id bigint(20) NOT NULL' ) );
	}
);

test(
	'index lines: dbDelta() parses plain ones and not ones with options after the columns',
	function () {
		same( array( 'type' => 'PRIMARY KEY', 'name' => '', 'columns' => array( 'id' ) ), Parser::index( 'PRIMARY KEY  (id)' ) );
		same( array( 'type' => 'UNIQUE KEY', 'name' => 'slug', 'columns' => array( 'site_id', 'slug' ) ), Parser::index( 'UNIQUE INDEX slug (site_id, slug(50))' ) );
		same( null, Parser::index( 'KEY email (email) USING BTREE' ) );
	}
);

test(
	'a second column on the line is found; commas inside parentheses and quotes are not',
	function () {
		same( true, Parser::has_second_column( 'lat float, lng float' ) );
		same( false, Parser::has_second_column( "price decimal(10,2) NOT NULL DEFAULT '0,5'" ) );
		same( false, Parser::has_second_column( "state enum('a','b') NOT NULL" ) );
	}
);

test(
	'integer display width is the cause on MariaDB, with the reported width as the fix',
	function () use ( $create ) {
		$r = Diagnosis::explain( 'ALTER TABLE wp_acme CHANGE COLUMN `id` id int unsigned NOT NULL AUTO_INCREMENT', context( array( 'Field' => 'id', 'Type' => 'int(10) unsigned' ), $create ) );
		same( 'int-display-width', $r['cause'] );
		same( '{prefix}acme.id', $r['target'] );
		same( true, false !== strpos( $r['fix'], 'int(10) unsigned' ) );
		same( true, false !== strpos( $r['detail'], 'MariaDB 11.8.6' ) );
	}
);

test(
	'enum values with capitals, spaces in a type, aliases and two columns on a line',
	function () use ( $create ) {
		same( 'enum-case', Diagnosis::explain( "ALTER TABLE wp_acme CHANGE COLUMN `state` state enum('Open','Closed') NOT NULL", context( array( 'Field' => 'state', 'Type' => "enum('Open','Closed')" ), $create ) )['cause'] );
		same( 'space-in-type', Diagnosis::explain( "ALTER TABLE wp_acme CHANGE COLUMN `price` price decimal(10, 2) NOT NULL DEFAULT '0'", context( array( 'Field' => 'price', 'Type' => 'decimal(10,2)' ), $create ) )['cause'] );
		$alias = Diagnosis::explain( "ALTER TABLE wp_acme CHANGE COLUMN `on` on boolean NOT NULL DEFAULT '0'", context( array( 'Field' => 'on', 'Type' => 'tinyint(1)' ), $create ) );
		same( 'type-alias', $alias['cause'] );
		same( true, false !== strpos( $alias['fix'], 'tinyint(1)' ) );
		$json = Diagnosis::explain( 'ALTER TABLE wp_acme CHANGE COLUMN `data` data json', context( array( 'Field' => 'data', 'Type' => 'longtext' ), $create ) );
		same( 'type-alias', $json['cause'] );
		same( true, false !== strpos( $json['fix'], 'MariaDB' ) );
		same( 'two-columns-one-line', Diagnosis::explain( 'ALTER TABLE wp_acme CHANGE COLUMN `lat` lat float, lng float', context( array( 'Field' => 'lat', 'Type' => 'float' ), $create ) )['cause'] );
		same( 'type-mismatch', Diagnosis::explain( 'ALTER TABLE wp_acme CHANGE COLUMN `x` x varchar(20)', context( array( 'Field' => 'x', 'Type' => 'char(20)' ), $create ) )['cause'] );
	}
);

test(
	'defaults: equal numbers written differently, quoted by the server, or different',
	function () use ( $create ) {
		$r = Diagnosis::explain( "ALTER TABLE wp_acme ALTER COLUMN `price` SET DEFAULT '0'", context( array( 'Field' => 'price', 'Default' => '0.00' ), $create ) );
		same( 'default-format', $r['cause'] );
		same( "Write the default as the database stores it: DEFAULT '0.00'.", $r['fix'] );
		same( 'default-quoted', Diagnosis::explain( "ALTER TABLE wp_acme ALTER COLUMN `s` SET DEFAULT 'x'", context( array( 'Field' => 's', 'Default' => "'x'" ), $create ) )['cause'] );
		same( 'default-mismatch', Diagnosis::explain( "ALTER TABLE wp_acme ALTER COLUMN `s` SET DEFAULT 'x'", context( array( 'Field' => 's', 'Default' => null ), $create ) )['cause'] );
	}
);

test(
	'constraint lines are not columns; indexes that cannot be parsed, have no name or differ in case',
	function () {
		same( 'not-a-column', Diagnosis::explain( 'ALTER TABLE wp_acme ADD COLUMN CONSTRAINT fk_site FOREIGN KEY (site_id) REFERENCES wp_sites (id)', context( null, 'CREATE TABLE wp_acme (id int)' ) )['cause'] );
		same( 'column-missing', Diagnosis::explain( 'ALTER TABLE wp_acme ADD COLUMN other int', context( null, 'CREATE TABLE wp_acme (id int)' ) )['cause'] );
		$syntax = Diagnosis::explain( 'ALTER TABLE wp_acme ADD `` (``)', context( null, "CREATE TABLE wp_acme (\n  id int,\n  KEY email (email) USING BTREE\n)" ) );
		same( 'index-syntax', $syntax['cause'] );
		same( '{prefix}acme (KEY email (email) USING BTREE)', $syntax['target'] );
		same( 'index-unnamed', Diagnosis::explain( 'ALTER TABLE wp_acme ADD KEY `` (`site_id`)', context( null, "CREATE TABLE wp_acme (\n  site_id int,\n  KEY (site_id)\n)" ) )['cause'] );
		$case = Diagnosis::explain(
			'ALTER TABLE wp_acme ADD KEY `name` (`name`)',
			context( null, "CREATE TABLE wp_acme (\n  Name int,\n  KEY name (name)\n)", true, array( array( 'Key_name' => 'name', 'Column_name' => 'Name' ) ) )
		);
		same( 'index-column-case', $case['cause'] );
	}
);

test(
	'the text report counts, groups and says when everything converges',
	function () {
		$base = array(
			'source'    => 'schema.sql',
			'server'    => array( 'name' => 'MySQL', 'version' => '8.4.6', 'mariadb' => false ),
			'tables'    => array( array( 'table' => '{prefix}a', 'error' => '' ) ),
			'results'   => array(),
			'ignored'   => array(),
			'wordpress' => '7.1.2',
		);
		$clean = Report::text( $base );
		same( true, false !== strpos( $clean, '1 table from schema.sql, MySQL 8.4.6, WordPress 7.1.2' ) );
		same( true, false !== strpos( $clean, 'Every table converges' ) );
		$base['results'] = array(
			array( 'status' => 'loops', 'statement' => 'ALTER …', 'kind' => 'type', 'target' => '{prefix}a.id', 'cause' => 'type-alias', 'detail' => 'd', 'fix' => 'f' ),
			array( 'status' => 'settles', 'statement' => 'ALTER …', 'kind' => 'type', 'target' => '{prefix}a.x', 'cause' => 'type-mismatch', 'detail' => 'd', 'fix' => 'f' ),
		);
		$base['tables'][] = array( 'table' => '{prefix}b', 'error' => 'Syntax error' );
		$base['ignored']  = array( 'INSERT INTO x' );
		$text             = Report::text( $base );
		same( true, false !== strpos( $text, '1 statement dbDelta() runs on every call, 1 that stops after one run, 1 table not created.' ) );
		same( true, false !== strpos( $text, "Runs on every call (1)\n  {prefix}a.id  [type-alias]" ) );
		same( true, false !== strpos( $text, 'Not created (1)' ) );
		same( true, false !== strpos( $text, 'Not checked (not CREATE TABLE): INSERT INTO x' ) );
	}
);

echo "# tests {$count}\n# fail {$failures}\n";
exit( $failures ? 1 : 0 );
