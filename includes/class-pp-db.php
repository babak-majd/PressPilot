<?php
/**
 * Database read layer — structured, read-only access to plugin and core tables, so
 * PressPilot can inspect data that plugins store outside options/meta (WooCommerce,
 * JetEngine, form/LMS plugins, …) for reporting and discovery.
 *
 * This layer is READ-ONLY and never accepts raw SQL. Every query is assembled by the
 * plugin from identifiers validated against `[A-Za-z0-9_]` and values bound through
 * `$wpdb->prepare()`; the caller chooses a table, columns, equality filters, ordering
 * and a capped row limit — never a SQL string. Writes go through the typed endpoints
 * (/content, /options, /meta, /terms) which carry their own validation and restore
 * points.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_DB {

	const MAX_ROWS = 500;

	private static function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check for a dynamic table; prepared, and schema lookups are not cached.
		return $found === $table;
	}

	private static function valid_ident( $name ) {
		return is_string( $name ) && preg_match( '/^[A-Za-z0-9_]+$/', $name );
	}

	/** List tables (optionally filtered by prefix) with row counts. */
	public static function tables( $args ) {
		global $wpdb;
		$prefix = isset( $args['prefix'] ) && '' !== $args['prefix'] ? (string) $args['prefix'] : $wpdb->prefix;
		$like   = $wpdb->esc_like( $prefix ) . '%';
		$names  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- listing tables by prefix; prepared, and schema lookups are not cached.
		$out    = array();
		foreach ( $names as $t ) {
			$out[] = array(
				'table' => $t,
				'rows'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is a real table name returned by SHOW TABLES; a row count that is not cached.
			);
		}
		return array( 'prefix' => $prefix, 'tables' => $out, 'count' => count( $out ) );
	}

	/** Column definitions for a table. */
	public static function describe( $table ) {
		if ( ! self::valid_ident( $table ) || ! self::table_exists( $table ) ) {
			if ( ! self::table_exists( $table ) ) {
				return PP_Helpers::error( 'pp_no_table', 'Table not found.', 404 );
			}
		}
		global $wpdb;
		$cols = $wpdb->get_results( "DESCRIBE `$table`", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is validated by valid_ident() and table_exists(); DESCRIBE takes an identifier, not a bindable value.
		return array( 'table' => $table, 'columns' => $cols );
	}

	/**
	 * Read rows with a structured, read-only query. No raw SQL: the caller supplies a
	 * table, optional columns, equality filters, ordering and a capped limit, and the
	 * query is built from validated identifiers with bound values.
	 *
	 * @param array $args { table, columns[], where{}, order, dir, limit }
	 * @return array|WP_Error
	 */
	public static function query( $args ) {
		global $wpdb;
		$limit = min( self::MAX_ROWS, max( 1, (int) ( isset( $args['limit'] ) ? $args['limit'] : 100 ) ) );

		$table = isset( $args['table'] ) ? (string) $args['table'] : '';
		if ( ! self::table_exists( $table ) ) {
			return PP_Helpers::error( 'pp_no_table', 'Provide an existing "table".', 400 );
		}
		$cols = '*';
		if ( ! empty( $args['columns'] ) && is_array( $args['columns'] ) ) {
			$safe = array_filter( $args['columns'], array( __CLASS__, 'valid_ident' ) );
			$cols = $safe ? '`' . implode( '`,`', $safe ) . '`' : '*';
		}
		$sql     = "SELECT $cols FROM `$table`";
		$params  = array();
		$where   = isset( $args['where'] ) && is_array( $args['where'] ) ? $args['where'] : array();
		$clauses = array();
		foreach ( $where as $col => $val ) {
			if ( ! self::valid_ident( $col ) ) {
				continue;
			}
			if ( null === $val ) {
				$clauses[] = "`$col` IS NULL";
			} else {
				$clauses[] = "`$col` = %s";
				$params[]  = $val;
			}
		}
		if ( $clauses ) {
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
		}
		if ( ! empty( $args['order'] ) && self::valid_ident( $args['order'] ) ) {
			$dir  = ( isset( $args['dir'] ) && 'desc' === strtolower( $args['dir'] ) ) ? 'DESC' : 'ASC';
			$sql .= " ORDER BY `{$args['order']}` $dir";
		}
		$sql     .= ' LIMIT ' . (int) $limit;
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is assembled only from identifiers validated by valid_ident(); row values are bound via $params.
		$rows     = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $prepared is a $wpdb->prepare() result or a query built from valid_ident()-validated identifiers; not cached by design.
		if ( null === $rows && $wpdb->last_error ) {
			return PP_Helpers::error( 'pp_sql_error', $wpdb->last_error, 400 );
		}
		return array( 'table' => $table, 'rows' => (array) $rows, 'count' => count( (array) $rows ) );
	}
}
