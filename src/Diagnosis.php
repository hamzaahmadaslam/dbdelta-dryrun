<?php
/**
 * Explains why dbDelta() wants to run a statement on a table that was just created from the same SQL.
 *
 * @package DbDeltaDryrun
 */

namespace DbDeltaDryrun;

/**
 * Pure functions: the caller passes what the database reported, so this runs without a database.
 */
final class Diagnosis {

	const INT_TYPES = array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint' );

	/** Types the server stores under another name. */
	const ALIASES = array(
		'integer'          => 'int',
		'bool'             => 'tinyint(1)',
		'boolean'          => 'tinyint(1)',
		'numeric'          => 'decimal',
		'dec'              => 'decimal',
		'fixed'            => 'decimal',
		'real'             => 'double',
		'double precision' => 'double',
		'json'             => 'longtext',
		'serial'           => 'bigint unsigned',
		'character'        => 'char',
	);

	/**
	 * One statement dbDelta() would run, with the cause and the fix.
	 *
	 * @param string     $statement The ALTER statement dbDelta() produced.
	 * @param array      $context   {
	 *     @type string     $table     Table name.
	 *     @type string     $create    The CREATE TABLE statement.
	 *     @type array|null $column    The DESCRIBE row for the column (Field, Type, Null, Key, Default, Extra).
	 *     @type array[]    $indexes   The SHOW INDEX rows for the table.
	 *     @type bool       $mariadb   Whether the server is MariaDB.
	 *     @type string     $version   The server version.
	 * }
	 * @return array{kind: string, target: string, cause: string, detail: string, fix: string}
	 */
	public static function explain( $statement, array $context ) {
		$table = $context['table'];
		if ( preg_match( '/^ALTER TABLE \S+ CHANGE COLUMN `([^`]+)` (.*)$/s', $statement, $m ) ) {
			return self::type_change( $m[1], $m[2], $context );
		}
		if ( preg_match( "/^ALTER TABLE \S+ ALTER COLUMN `([^`]+)` SET DEFAULT '(.*)'$/s", $statement, $m ) ) {
			return self::default_change( $m[1], $m[2], $context );
		}
		if ( preg_match( '/^ALTER TABLE \S+ ADD COLUMN (.*)$/s', $statement, $m ) ) {
			return self::added_column( $m[1], $context );
		}
		if ( preg_match( '/^ALTER TABLE \S+ ADD (.*)$/s', $statement, $m ) ) {
			return self::added_index( $m[1], $context );
		}
		return self::result( 'other', $table, 'unknown', 'dbDelta() produced a statement this tool does not classify.', 'Read the statement and the table definition side by side.' );
	}

	/**
	 * A CHANGE COLUMN: the type dbDelta() read from the SQL differs from the type DESCRIBE reports.
	 */
	private static function type_change( $field, $line, array $c ) {
		$target   = "{$c['table']}.{$field}";
		$reported = isset( $c['column']['Type'] ) ? $c['column']['Type'] : '';
		$wanted   = strtolower( Parser::field_type( $field, $line ) );
		$detail   = "The SQL gives the type as \"{$wanted}\" and the database reports \"{$reported}\".";

		if ( Parser::has_second_column( $line ) ) {
			return self::result( 'type', $target, 'two-columns-one-line', "{$detail} The line holds more than one column, and dbDelta() reads one column per line.", 'Put each column on its own line.' );
		}
		// The written type, up to the first space: a space inside the parentheses cuts it short.
		if ( preg_match( '/\([^)]*$/', $wanted ) ) {
			return self::result( 'type', $target, 'space-in-type', "{$detail} dbDelta() reads the type up to the first space, and this type has a space inside its parentheses.", "Remove the spaces inside the parentheses: {$reported}." );
		}
		if ( preg_match( '/^(enum|set)\(/i', $reported ) && strtolower( $reported ) === $wanted && $reported !== $wanted ) {
			return self::result( 'type', $target, 'enum-case', "{$detail} dbDelta() lower-cases the type from the SQL but compares it with the database's type as reported, so values with capital letters never match.", 'Use lower-case values in the ENUM or SET, or keep this column out of dbDelta().' );
		}
		$strip = function ( $type ) {
			return preg_replace( '/(.+)\(\d*\)(.*)/', '$1$2', $type );
		};
		$base_wanted   = strtok( $strip( $wanted ), ' ' );
		$base_reported = strtok( $strip( strtolower( $reported ) ), ' ' );
		if ( in_array( $base_wanted, self::INT_TYPES, true ) && in_array( $base_reported, self::INT_TYPES, true ) && $strip( $wanted ) === $strip( strtolower( $reported ) ) ) {
			$server = $c['mariadb'] ? "MariaDB {$c['version']}" : "MySQL {$c['version']}";
			return self::result( 'type', $target, 'int-display-width', "{$detail} Only the display width differs. WordPress ignores that difference on MySQL 8.0.17 and later, but not on {$server}.", "Write the width the server reports, \"{$reported}\": MySQL 8.0.17 and later ignore it, and it matches MariaDB." );
		}
		$written = strtolower( preg_replace( '/^`?' . preg_quote( $field, '/' ) . '`?\s+/i', '', $line ) );
		foreach ( self::ALIASES as $alias => $canonical ) {
			if ( preg_match( '/^' . preg_quote( $alias, '/' ) . '\b/', $written ) ) {
				if ( 'json' === $alias ) {
					return self::result( 'type', $target, 'type-alias', "{$detail} MariaDB stores JSON as LONGTEXT (MySQL has a native JSON type), so on MariaDB the names never match.", 'Write longtext if the plugin must run on MariaDB (and validate the JSON in PHP), or keep this column out of dbDelta() and create it once with its own ALTER TABLE.' );
				}
				return self::result( 'type', $target, 'type-alias', "{$detail} \"{$alias}\" is stored as \"{$canonical}\", so the names never match.", "Write the type the server reports: {$reported}." );
			}
		}
		return self::result( 'type', $target, 'type-mismatch', $detail, "Write the type exactly as the server reports it: {$reported}." );
	}

	/**
	 * An ALTER COLUMN SET DEFAULT: the quoted default in the SQL differs from DESCRIBE's Default.
	 */
	private static function default_change( $field, $value, array $c ) {
		$target   = "{$c['table']}.{$field}";
		$reported = isset( $c['column'] ) && array_key_exists( 'Default', $c['column'] ) ? $c['column']['Default'] : null;
		$shown    = null === $reported ? 'NULL' : "'{$reported}'";
		$detail   = "The SQL gives DEFAULT '{$value}' and the database reports {$shown}.";
		if ( null !== $reported && is_numeric( $value ) && is_numeric( $reported ) && (float) $value === (float) $reported ) {
			return self::result( 'default', $target, 'default-format', "{$detail} The values are equal as numbers, but dbDelta() compares them as text.", "Write the default as the database stores it: DEFAULT '{$reported}'." );
		}
		if ( null !== $reported && "'{$value}'" === $reported ) {
			return self::result( 'default', $target, 'default-quoted', "{$detail} The server reports the default with its quotes, and dbDelta() compares it without them.", 'Keep this column\'s default out of dbDelta() (set it in a one-off ALTER), or accept one ALTER per call on this server.' );
		}
		return self::result( 'default', $target, 'default-mismatch', $detail, null === $reported ? 'Check that the column can hold this default, and that the SQL and the table agree on NULL.' : "Write the default as the database stores it: DEFAULT '{$reported}'." );
	}

	/**
	 * An ADD COLUMN: a line in the SQL that dbDelta() took for a column the table does not have.
	 */
	private static function added_column( $line, array $c ) {
		preg_match( '|^([^ ]*)|', $line, $f );
		$name   = trim( $f[1], '`' );
		$target = "{$c['table']}.{$name}";
		if ( in_array( strtolower( $name ), array( 'constraint', 'foreign', 'check' ), true ) ) {
			return self::result( 'column', $target, 'not-a-column', "dbDelta() reads the line \"{$line}\" as a column called \"{$name}\", tries to add it, and fails every time.", 'Remove the constraint from the SQL given to dbDelta() and add it once with its own ALTER TABLE.' );
		}
		return self::result( 'column', $target, 'column-missing', "dbDelta() wants to add \"{$name}\", but the table created from the same SQL has no such column: the line was not created as written.", 'Check the line for a typo, a reserved word or a comment, and put each column on its own line.' );
	}

	/**
	 * An ADD (index): an index line that does not match any index SHOW INDEX reports.
	 */
	private static function added_index( $definition, array $c ) {
		$line   = null;
		$parsed = null;
		foreach ( Parser::lines( $c['create'] )['indexes'] as $candidate ) {
			$p = Parser::index( $candidate );
			if ( null === $p ) {
				$line = $candidate;
				break;
			}
			$normal = "{$p['type']} " . ( 'PRIMARY KEY' === $p['type'] ? '' : '`' . strtolower( $p['name'] ) . '`' );
			if ( 0 === stripos( $definition, trim( $normal ) ) || ( '' === $p['name'] && 'PRIMARY KEY' !== $p['type'] ) ) {
				$line   = $candidate;
				$parsed = $p;
				break;
			}
		}
		$target = "{$c['table']} {$definition}";
		if ( null !== $line && null === $parsed ) {
			return self::result( 'index', "{$c['table']} ({$line})", 'index-syntax', "dbDelta() cannot parse the index line \"{$line}\" (a USING clause, a COMMENT or another option after the column list), so it never finds it on the table.", 'Write the index as KEY `name` (column, …) with nothing after the closing parenthesis.' );
		}
		if ( null !== $parsed && '' === $parsed['name'] && 'PRIMARY KEY' !== $parsed['type'] ) {
			return self::result( 'index', $target, 'index-unnamed', "The index \"{$line}\" has no name. The server names it after its first column, and dbDelta() compares names, so it adds the index again.", 'Give every index other than the primary key a name: KEY `name` (column).' );
		}
		if ( null !== $parsed ) {
			$actual = array();
			foreach ( $c['indexes'] as $row ) {
				$actual[ strtolower( $row['Column_name'] ) ] = $row['Column_name'];
			}
			foreach ( $parsed['columns'] as $column ) {
				if ( isset( $actual[ strtolower( $column ) ] ) && $actual[ strtolower( $column ) ] !== $column ) {
					return self::result( 'index', $target, 'index-column-case', "The index names the column \"{$column}\" and the table calls it \"{$actual[ strtolower( $column ) ]}\". dbDelta() compares the names with their case.", 'Write column names in the index exactly as in the column definitions.' );
				}
			}
		}
		return self::result( 'index', $target, 'index-mismatch', 'dbDelta() does not find this index among the indexes SHOW INDEX reports for the table.', 'Compare the index line with SHOW INDEX output: type (UNIQUE, FULLTEXT), name and column order must match.' );
	}

	/**
	 * A result row.
	 */
	private static function result( $kind, $target, $cause, $detail, $fix ) {
		return compact( 'kind', 'target', 'cause', 'detail', 'fix' );
	}
}
