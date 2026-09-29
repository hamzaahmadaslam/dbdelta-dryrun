<?php
/**
 * The plain-text report.
 *
 * @package DbDeltaDryrun
 */

namespace DbDeltaDryrun;

/**
 * Pure function: formats the report array the command builds.
 */
final class Report {

	/**
	 * The report as text.
	 *
	 * @param array $report The report.
	 * @return string
	 */
	public static function text( array $report ) {
		$loops    = array_values( array_filter( $report['results'], function ( $r ) { return 'loops' === $r['status']; } ) );
		$settles  = array_values( array_filter( $report['results'], function ( $r ) { return 'settles' === $r['status']; } ) );
		$failed   = array_values( array_filter( $report['tables'], function ( $t ) { return '' !== $t['error']; } ) );
		$count    = count( $report['tables'] );
		$plural   = function ( $n, $one, $many ) {
			return $n . ' ' . ( 1 === $n ? $one : $many );
		};
		$out   = array();
		$out[] = sprintf(
			'dbdelta-dryrun: %s from %s, %s %s, WordPress %s',
			$plural( $count, 'table', 'tables' ),
			$report['source'],
			$report['server']['name'],
			$report['server']['version'],
			$report['wordpress']
		);
		$out[] = sprintf(
			'%s dbDelta() runs on every call, %s after one run, %s.',
			$plural( count( $loops ), 'statement', 'statements' ),
			$plural( count( $settles ), 'that stops', 'that stop' ),
			$plural( count( $failed ), 'table not created', 'tables not created' )
		);
		if ( ! $loops && ! $settles && ! $failed ) {
			$out[] = 'Every table converges: a second dbDelta() call changes nothing.';
		}
		$groups = array(
			'Runs on every call' => $loops,
			'Stops after one run' => $settles,
		);
		foreach ( $groups as $label => $group ) {
			if ( ! $group ) {
				continue;
			}
			$out[] = '';
			$out[] = sprintf( '%s (%d)', $label, count( $group ) );
			foreach ( $group as $r ) {
				$out[] = sprintf( '  %s  [%s]', $r['target'], $r['cause'] );
				$out[] = '    ' . preg_replace( '/\s+/', ' ', $r['statement'] );
				$out[] = '    ' . $r['detail'];
				$out[] = '    Fix: ' . $r['fix'];
			}
		}
		if ( $failed ) {
			$out[] = '';
			$out[] = sprintf( 'Not created (%d)', count( $failed ) );
			foreach ( $failed as $t ) {
				$out[] = sprintf( '  %s: %s', $t['table'], $t['error'] );
			}
		}
		if ( $report['ignored'] ) {
			$out[] = '';
			$out[] = 'Not checked (not CREATE TABLE): ' . implode( '; ', $report['ignored'] );
		}
		return implode( "\n", $out );
	}
}
