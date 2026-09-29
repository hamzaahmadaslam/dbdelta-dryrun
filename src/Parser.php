<?php
/**
 * Reads CREATE TABLE statements the way dbDelta() does (wp-admin/includes/upgrade.php, WordPress 7.1.2), so a
 * diagnosis sees the same field names, types and index definitions dbDelta compared.
 *
 * @package DbDeltaDryrun
 */

namespace DbDeltaDryrun;

/**
 * Pure functions: no database, no WordPress.
 */
final class Parser {

	/**
	 * Splits SQL into statements the way dbDelta() does with a string: on every semicolon.
	 *
	 * @param string $sql SQL text.
	 * @return string[]
	 */
	public static function statements( $sql ) {
		$sql = preg_replace( '/^\s*--[^\n]*$/m', '', $sql );
		return array_values( array_filter( array_map( 'trim', explode( ';', $sql ) ), 'strlen' ) );
	}

	/**
	 * The table name dbDelta() reads from a CREATE TABLE statement: the first word after "CREATE TABLE".
	 * For "CREATE TABLE IF NOT EXISTS x" that word is "IF".
	 *
	 * @param string $statement One statement.
	 * @return string|null
	 */
	public static function table( $statement ) {
		if ( ! preg_match( '|CREATE TABLE ([^ ]*)|', $statement, $m ) ) {
			return null;
		}
		return trim( $m[1], '`' );
	}

	/**
	 * The table the statement really creates, for reporting: skips IF NOT EXISTS.
	 *
	 * @param string $statement One statement.
	 * @return string|null
	 */
	public static function real_table( $statement ) {
		if ( ! preg_match( '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^\s`(]+)`?/i', $statement, $m ) ) {
			return null;
		}
		return $m[1];
	}

	/**
	 * The lines between the outer parentheses, trimmed as dbDelta() trims them, keyed by lower-cased field name.
	 * Index lines are returned separately.
	 *
	 * @param string $statement One CREATE TABLE statement.
	 * @return array{fields: array<string,string>, indexes: string[]}
	 */
	public static function lines( $statement ) {
		$fields  = array();
		$indexes = array();
		if ( ! preg_match( '|\((.*)\)|ms', $statement, $m ) ) {
			return compact( 'fields', 'indexes' );
		}
		foreach ( explode( "\n", trim( $m[1] ) ) as $line ) {
			$line = trim( $line, " \t\n\r\0\x0B," );
			preg_match( '|^([^ ]*)|', $line, $f );
			$name = strtolower( trim( $f[1], '`' ) );
			if ( in_array( $name, array( '', 'primary', 'index', 'fulltext', 'unique', 'key', 'spatial' ), true ) ) {
				if ( '' !== $name ) {
					$indexes[] = $line;
				}
				continue;
			}
			$fields[ $name ] = $line;
		}
		return compact( 'fields', 'indexes' );
	}

	/**
	 * The column type dbDelta() reads from a field line: the first word after the field name, plus " unsigned".
	 *
	 * @param string $field Field name as the database reports it.
	 * @param string $line  The field line.
	 * @return string
	 */
	public static function field_type( $field, $line ) {
		preg_match( '|`?' . $field . '`? ([^ ]*( unsigned)?)|i', $line, $m );
		return isset( $m[1] ) ? $m[1] : '';
	}

	/**
	 * The quoted DEFAULT dbDelta() reads from a field line, or null when there is none.
	 *
	 * @param string $line The field line.
	 * @return string|null
	 */
	public static function field_default( $line ) {
		return preg_match( "| DEFAULT '(.*?)'|i", $line, $m ) ? $m[1] : null;
	}

	/**
	 * Whether dbDelta()'s index pattern matches an index line at all.
	 *
	 * @param string $line An index line.
	 * @return array|null { type, name, columns } or null.
	 */
	public static function index( $line ) {
		$ok = preg_match(
			'/^(?P<index_type>PRIMARY\s+KEY|(?:UNIQUE|FULLTEXT|SPATIAL)\s+(?:KEY|INDEX)|KEY|INDEX)\s+(?:`?(?P<index_name>(?:[0-9a-zA-Z$_-]|[\xC2-\xDF][\x80-\xBF])+)`?\s+)*\((?P<index_columns>.+?)\)$/im',
			$line,
			$m
		);
		if ( ! $ok ) {
			return null;
		}
		$type    = str_replace( 'INDEX', 'KEY', strtoupper( preg_replace( '/\s+/', ' ', trim( $m['index_type'] ) ) ) );
		$columns = array();
		foreach ( array_map( 'trim', explode( ',', $m['index_columns'] ) ) as $column ) {
			preg_match( '/`?(?P<name>(?:[0-9a-zA-Z$_-]|[\xC2-\xDF][\x80-\xBF])+)`?/', $column, $c );
			$columns[] = isset( $c['name'] ) ? $c['name'] : $column;
		}
		return array(
			'type'    => $type,
			'name'    => isset( $m['index_name'] ) ? $m['index_name'] : '',
			'columns' => $columns,
		);
	}

	/**
	 * Whether a line holds more than one column definition: a comma outside parentheses and quotes, followed by
	 * something that looks like a column name and a type.
	 *
	 * @param string $line A field line.
	 * @return bool
	 */
	public static function has_second_column( $line ) {
		$depth = 0;
		$quote = '';
		$len   = strlen( $line );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $line[ $i ];
			if ( '' !== $quote ) {
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( "'" === $ch || '"' === $ch ) {
				$quote = $ch;
			} elseif ( '(' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch ) {
				--$depth;
			} elseif ( ',' === $ch && 0 === $depth && preg_match( '/^\s*`?[A-Za-z_][A-Za-z0-9_]*`?\s+[A-Za-z]/', substr( $line, $i + 1 ) ) ) {
				return true;
			}
		}
		return false;
	}
}
