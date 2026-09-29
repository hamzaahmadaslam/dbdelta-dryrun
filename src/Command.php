<?php
/**
 * The WP-CLI command.
 *
 * @package DbDeltaDryrun
 */

namespace DbDeltaDryrun;

use WP_CLI;

/**
 * Finds CREATE TABLE statements that dbDelta() never stops altering.
 *
 * A plugin that calls dbDelta() on every request, or on every version check, runs whatever ALTER TABLE statements
 * dbDelta() still thinks are needed. When the SQL and the server disagree about how a column looks, that ALTER runs
 * every time. This command creates the tables under a scratch prefix, asks dbDelta() what it would change, lets it
 * change it once, asks again, and drops the tables.
 */
final class Command {

	/**
	 * Checks that the CREATE TABLE statements in SQL files, or the ones a plugin passes to dbDelta(), converge.
	 *
	 * Creates each table under a scratch prefix in the current database, runs dbDelta() against it with every write
	 * blocked, lets dbDelta() apply its changes once, asks again, then drops the scratch tables. Existing tables are
	 * never touched. Refuses to run where wp_get_environment_type() is "production" unless told to.
	 *
	 * ## OPTIONS
	 *
	 * [<file>...]
	 * : SQL files holding CREATE TABLE statements. Start table names with {prefix} (or the site's table prefix) and
	 * write {charset_collate} where the plugin appends $wpdb->get_charset_collate().
	 *
	 * [--from=<callable>]
	 * : Instead of files, call this function or static method (for example My_Plugin_Install::create_tables) and
	 * check the SQL it passes to dbDelta(). dbDelta() is stopped before it runs anything; any other writes the
	 * function makes still happen, so use a scratch site.
	 *
	 * [--prefix=<prefix>]
	 * : Scratch table prefix. Default: dbdd, six random characters and an underscore.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * [--strict]
	 * : Also exit 1 on changes that stop after dbDelta() applies them once.
	 *
	 * [--keep]
	 * : Leave the scratch tables in place for inspection.
	 *
	 * [--allow-production]
	 * : Run even though the environment type is "production". The command only creates, alters and drops its own
	 * scratch tables, but it does so in the site's database.
	 *
	 * ## EXAMPLES
	 *
	 *     # Check a schema file
	 *     $ wp dbdelta-dryrun check schema.sql
	 *
	 *     # Check what a plugin's installer passes to dbDelta()
	 *     $ wp dbdelta-dryrun check --from='Acme\Install::create_tables'
	 *
	 * @param string[] $args       Files.
	 * @param array    $assoc_args Options.
	 */
	public function check( $args, $assoc_args ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'text';
		if ( 'production' === wp_get_environment_type() && empty( $assoc_args['allow-production'] ) ) {
			WP_CLI::error( 'The environment type is "production". Run this on a development or staging copy, or pass --allow-production.' );
		}
		$scratch = isset( $assoc_args['prefix'] ) ? $assoc_args['prefix'] : 'dbdd' . substr( md5( uniqid( '', true ) ), 0, 6 ) . '_';
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,20}$/', $scratch ) ) {
			WP_CLI::error( 'The scratch prefix may only contain letters, digits and underscores (20 at most).' );
		}

		list( $sql, $source ) = $this->collect( $args, $assoc_args );
		$statements           = array();
		$ignored              = array();
		foreach ( Parser::statements( $sql ) as $statement ) {
			$real = Parser::real_table( $statement );
			if ( null === $real ) {
				$ignored[] = self::first_line( $statement );
				continue;
			}
			$statements[] = $this->to_scratch( $statement, $real, $scratch );
		}
		if ( ! $statements ) {
			WP_CLI::error( 'No CREATE TABLE statements found.' );
		}

		$tables = array();
		foreach ( $statements as $statement ) {
			$table = Parser::real_table( $statement );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				WP_CLI::error( "The scratch table {$table} already exists. Choose another --prefix." );
			}
			$tables[ $table ] = array(
				'table'  => $table,
				'create' => $statement,
				'error'  => '',
			);
		}

		$suppress = $wpdb->suppress_errors( true );
		// dbDelta() itself warns about index lines it cannot parse; the report explains them instead.
		set_error_handler(
			function ( $errno, $errstr, $errfile ) {
				return false !== strpos( str_replace( '\\', '/', $errfile ), 'wp-admin/includes/upgrade.php' );
			},
			E_WARNING | E_NOTICE
		);
		try {
			// Pass 1: create the tables, as dbDelta() does for a table that does not exist yet.
			foreach ( $tables as $table => $info ) {
				$wpdb->query( $info['create'] );
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
					$tables[ $table ]['error'] = $wpdb->last_error ? $wpdb->last_error : 'The table was not created.';
				}
			}
			$live = array_filter(
				$statements,
				function ( $statement ) use ( $tables ) {
					return '' === $tables[ Parser::real_table( $statement ) ]['error'];
				}
			);

			$snapshot = array();
			foreach ( array_keys( $tables ) as $table ) {
				if ( '' === $tables[ $table ]['error'] ) {
					$snapshot[ $table ] = array(
						'columns' => $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A ),
						'indexes' => $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ),
					);
				}
			}

			// Pass 2: what dbDelta() would run now, with every write blocked.
			$first = $live ? $this->blocked( $live, $scratch ) : array();
			// Pass 3: let dbDelta() apply it once, as a site would on its next call.
			if ( $live ) {
				dbDelta( array_values( $live ), true );
			}
			// Pass 4: what it would run after that.
			$second = $live ? $this->blocked( $live, $scratch ) : array();
		} finally {
			if ( empty( $assoc_args['keep'] ) ) {
				foreach ( array_keys( $tables ) as $table ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
				}
			}
			$wpdb->suppress_errors( $suppress );
			restore_error_handler();
		}

		$server  = $this->server();
		$results = array();
		foreach ( array_unique( array_merge( $first, $second ) ) as $statement ) {
			$table   = $this->table_of( $statement, array_keys( $tables ) );
			$status  = in_array( $statement, $second, true ) ? 'loops' : 'settles';
			$context = array(
				'table'   => $this->shown( $table, $scratch ),
				'create'  => $table ? $tables[ $table ]['create'] : '',
				'column'  => null,
				'indexes' => $table && isset( $snapshot[ $table ] ) ? $snapshot[ $table ]['indexes'] : array(),
				'mariadb' => $server['mariadb'],
				'version' => $server['version'],
			);
			if ( preg_match( '/`([^`]+)`/', $statement, $m ) && $table && isset( $snapshot[ $table ] ) ) {
				foreach ( $snapshot[ $table ]['columns'] as $row ) {
					if ( strtolower( $row['Field'] ) === strtolower( $m[1] ) ) {
						$context['column'] = $row;
					}
				}
			}
			if ( preg_match( '/^\s*CREATE TABLE/i', $statement ) ) {
				$explained = array(
					'kind'   => 'table',
					'target' => $context['table'],
					'cause'  => 'if-not-exists',
					'detail' => 'dbDelta() reads the word after CREATE TABLE as the table name, which is "IF" here. It cannot find a table called IF, so it runs the whole CREATE statement on every call and never compares the columns.',
					'fix'    => 'Write CREATE TABLE without IF NOT EXISTS: dbDelta() already skips tables that exist.',
				);
			} else {
				$explained = Diagnosis::explain( $statement, $context );
			}
			$results[] = array_merge(
				array(
					'status'    => $status,
					'statement' => $this->shown( $statement, $scratch ),
				),
				$explained
			);
		}
		usort(
			$results,
			function ( $a, $b ) {
				return strcmp( $a['status'], $b['status'] ) ?: strcmp( $a['target'], $b['target'] );
			}
		);

		$report = array(
			'source'   => $source,
			'server'   => $server,
			'scratch'  => $scratch,
			'tables'   => array_values(
				array_map(
					function ( $info ) use ( $scratch ) {
						return array(
							'table' => $this->shown( $info['table'], $scratch ),
							'error' => $info['error'],
						);
					},
					$tables
				)
			),
			'results'  => $results,
			'ignored'  => $ignored,
			'wordpress' => get_bloginfo( 'version' ),
		);

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			WP_CLI::line( Report::text( $report ) );
		}

		$failed = array_filter(
			$results,
			function ( $r ) use ( $assoc_args ) {
				return 'loops' === $r['status'] || ! empty( $assoc_args['strict'] );
			}
		);
		$errors = array_filter(
			$tables,
			function ( $t ) {
				return '' !== $t['error'];
			}
		);
		if ( $failed || $errors ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * The SQL to check, from files or from a callable, and a label for it.
	 */
	private function collect( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['from'] ) ) {
			$callable = $assoc_args['from'];
			if ( ! is_callable( $callable ) ) {
				WP_CLI::error( "Not callable: {$callable}. Is the plugin that defines it active?" );
			}
			$captured = array();
			$catch    = function ( $queries ) use ( &$captured ) {
				foreach ( (array) $queries as $query ) {
					$captured[] = $query;
				}
				return array();
			};
			add_filter( 'dbdelta_queries', $catch, PHP_INT_MAX );
			try {
				call_user_func( $callable );
			} finally {
				remove_filter( 'dbdelta_queries', $catch, PHP_INT_MAX );
			}
			if ( ! $captured ) {
				WP_CLI::error( "{$callable} did not call dbDelta()." );
			}
			return array( implode( ";\n", $captured ), $callable );
		}
		if ( ! $args ) {
			WP_CLI::error( 'Give one or more SQL files, or --from=<callable>.' );
		}
		$sql = '';
		foreach ( $args as $file ) {
			if ( ! is_readable( $file ) ) {
				WP_CLI::error( "Cannot read {$file}." );
			}
			$sql .= file_get_contents( $file ) . ";\n";
		}
		return array( $sql, implode( ', ', $args ) );
	}

	/**
	 * The statement with its table under the scratch prefix, and {charset_collate} filled in.
	 */
	private function to_scratch( $statement, $real, $scratch ) {
		global $wpdb;
		$statement = str_replace( '{charset_collate}', $wpdb->get_charset_collate(), $statement );
		if ( 0 === strpos( $real, '{prefix}' ) ) {
			$name = $scratch . substr( $real, strlen( '{prefix}' ) );
		} elseif ( '' !== $wpdb->prefix && 0 === strpos( $real, $wpdb->prefix ) ) {
			$name = $scratch . substr( $real, strlen( $wpdb->prefix ) );
		} else {
			$name = $scratch . $real;
		}
		$statement = str_replace( '{prefix}', $scratch, $statement );
		return preg_replace( '/(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?)' . preg_quote( str_replace( '{prefix}', $scratch, $real ), '/' ) . '/i', '${1}' . $name, $statement, 1 );
	}

	/**
	 * Runs dbDelta() with every statement that would change a scratch table blocked, and returns those statements.
	 */
	private function blocked( array $statements, $scratch ) {
		$caught = array();
		$block  = function ( $query ) use ( &$caught, $scratch ) {
			if ( preg_match( '/^\s*(ALTER|CREATE|INSERT|UPDATE|REPLACE|DELETE|DROP)\b/i', $query ) && false !== stripos( $query, $scratch ) ) {
				$caught[] = trim( $query );
				return '';
			}
			return $query;
		};
		add_filter( 'query', $block, PHP_INT_MAX );
		try {
			dbDelta( array_values( $statements ), true );
		} finally {
			remove_filter( 'query', $block, PHP_INT_MAX );
		}
		return $caught;
	}

	/**
	 * Which scratch table a statement touches.
	 */
	private function table_of( $statement, array $tables ) {
		usort(
			$tables,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $tables as $table ) {
			if ( false !== strpos( $statement, $table ) ) {
				return $table;
			}
		}
		return null;
	}

	/**
	 * Text with the scratch prefix shown as {prefix}.
	 */
	private function shown( $text, $scratch ) {
		return null === $text ? '' : str_replace( $scratch, '{prefix}', $text );
	}

	/**
	 * The database server's name and version.
	 */
	private function server() {
		global $wpdb;
		$info    = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		$mariadb = false !== stripos( $info, 'MariaDB' );
		$version = $mariadb && preg_match( '/(\d+\.\d+\.\d+)-MariaDB/i', $info, $m ) ? $m[1] : $wpdb->db_version();
		return array(
			'name'    => $mariadb ? 'MariaDB' : 'MySQL',
			'version' => $version,
			'mariadb' => $mariadb,
		);
	}

	/**
	 * The first line of a statement, for the list of ignored ones.
	 */
	private static function first_line( $statement ) {
		$line = strtok( trim( $statement ), "\n" );
		return strlen( $line ) > 80 ? substr( $line, 0, 79 ) . '…' : $line;
	}
}
