<?php
/**
 * Database layer — generic, guarded access to custom plugin tables, so PressPilot can
 * configure plugins that store their data outside options/meta (WooCommerce, JetEngine,
 * form/LMS plugins, …). This is the reach that makes "any plugin" possible.
 *
 * Safety: reads are capped; writes support dry-run, cap the number of affected rows,
 * return the before-image of changed rows, and refuse WordPress core tables unless
 * force=true (use the typed /options, /meta, /content endpoints for those).
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_DB {

	const MAX_ROWS = 500;

	/** Core tables that must not be written through the raw layer (use typed endpoints). */
	private static function core_tables() {
		global $wpdb;
		$core = array( 'options', 'posts', 'postmeta', 'users', 'usermeta', 'terms', 'termmeta',
			'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'links' );
		return array_map( function ( $t ) use ( $wpdb ) {
			return $wpdb->prefix . $t;
		}, $core );
	}

	private static function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
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
		$names  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		$out    = array();
		foreach ( $names as $t ) {
			$out[] = array(
				'table' => $t,
				'rows'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ), // table name from SHOW TABLES, safe.
			);
		}
		return array( 'prefix' => $prefix, 'tables' => $out, 'count' => count( $out ) );
	}

	/** Column definitions for a table. */
	public static function describe( $table ) {
		if ( ! self::valid_ident( $table ) || ! self::table_exists( $table ) ) {
			// allow full prefixed name too
			global $wpdb;
			if ( ! self::table_exists( $table ) ) {
				return PP_Helpers::error( 'pp_no_table', 'Table not found.', 404 );
			}
		}
		global $wpdb;
		$cols = $wpdb->get_results( "DESCRIBE `$table`", ARRAY_A );
		return array( 'table' => $table, 'columns' => $cols );
	}

	/**
	 * Read rows. Either a structured query (table + equality where) or a validated
	 * read-only raw SELECT.
	 *
	 * @param array $args { table, columns[], where{}, order, dir, limit, raw }
	 * @return array|WP_Error
	 */
	public static function select( $args ) {
		global $wpdb;
		$limit = min( self::MAX_ROWS, max( 1, (int) ( isset( $args['limit'] ) ? $args['limit'] : 100 ) ) );

		if ( ! empty( $args['raw'] ) ) {
			$sql = trim( (string) $args['raw'] );
			if ( ! preg_match( '/^SELECT\b/i', $sql ) || false !== strpos( rtrim( $sql, ';' ), ';' ) ) {
				return PP_Helpers::error( 'pp_bad_sql', 'raw must be a single read-only SELECT statement.', 400 );
			}
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( null === $rows && $wpdb->last_error ) {
				return PP_Helpers::error( 'pp_sql_error', $wpdb->last_error, 400 );
			}
			return array( 'rows' => array_slice( (array) $rows, 0, self::MAX_ROWS ), 'count' => count( (array) $rows ) );
		}

		$table = isset( $args['table'] ) ? (string) $args['table'] : '';
		if ( ! self::table_exists( $table ) ) {
			return PP_Helpers::error( 'pp_no_table', 'Provide an existing "table" (or a raw SELECT).', 400 );
		}
		$cols = '*';
		if ( ! empty( $args['columns'] ) && is_array( $args['columns'] ) ) {
			$safe = array_filter( $args['columns'], array( __CLASS__, 'valid_ident' ) );
			$cols = $safe ? '`' . implode( '`,`', $safe ) . '`' : '*';
		}
		$sql    = "SELECT $cols FROM `$table`";
		$params = array();
		$where  = isset( $args['where'] ) && is_array( $args['where'] ) ? $args['where'] : array();
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
		$sql .= ' LIMIT ' . (int) $limit;
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		if ( null === $rows && $wpdb->last_error ) {
			return PP_Helpers::error( 'pp_sql_error', $wpdb->last_error, 400 );
		}
		return array( 'table' => $table, 'rows' => (array) $rows, 'count' => count( (array) $rows ) );
	}

	/**
	 * Write rows (insert / update / delete) with guardrails.
	 *
	 * @param array $args { op, table, data{}, where{}, dry_run, force, limit }
	 * @return array|WP_Error
	 */
	public static function write( $args ) {
		global $wpdb;
		$op    = strtolower( isset( $args['op'] ) ? (string) $args['op'] : '' );
		$table = isset( $args['table'] ) ? (string) $args['table'] : '';
		$force = ! empty( $args['force'] );
		$dry   = ! empty( $args['dry_run'] );
		if ( ! in_array( $op, array( 'insert', 'update', 'delete' ), true ) ) {
			return PP_Helpers::error( 'pp_bad_op', 'op must be insert|update|delete.', 400 );
		}
		if ( ! self::table_exists( $table ) ) {
			return PP_Helpers::error( 'pp_no_table', 'Table not found.', 404 );
		}
		if ( ! $force && in_array( $table, self::core_tables(), true ) ) {
			return PP_Helpers::error( 'pp_core_table', 'Refusing to write a WordPress core table via the raw DB layer — use /options, /meta or /content (or force:true).', 400 );
		}
		$data  = isset( $args['data'] ) && is_array( $args['data'] ) ? $args['data'] : array();
		$where = isset( $args['where'] ) && is_array( $args['where'] ) ? $args['where'] : array();

		if ( 'insert' === $op ) {
			if ( empty( $data ) ) {
				return PP_Helpers::error( 'pp_no_data', 'insert needs "data".', 400 );
			}
			if ( $dry ) {
				return array( 'dry_run' => true, 'op' => 'insert', 'table' => $table, 'would_insert' => $data );
			}
			$ok = $wpdb->insert( $table, $data );
			if ( false === $ok ) {
				return PP_Helpers::error( 'pp_insert_failed', $wpdb->last_error ?: 'insert failed', 400 );
			}
			return array( 'inserted' => 1, 'insert_id' => (int) $wpdb->insert_id, 'table' => $table );
		}

		// update / delete need a where clause; capture the before-image first.
		if ( empty( $where ) ) {
			return PP_Helpers::error( 'pp_no_where', $op . ' needs a "where" clause (refusing to touch the whole table).', 400 );
		}
		$before = self::select( array( 'table' => $table, 'where' => $where, 'limit' => self::MAX_ROWS ) );
		$affected = is_array( $before ) && isset( $before['rows'] ) ? $before['rows'] : array();
		$cap = min( self::MAX_ROWS, max( 1, (int) ( isset( $args['limit'] ) ? $args['limit'] : self::MAX_ROWS ) ) );
		if ( count( $affected ) > $cap && ! $force ) {
			return PP_Helpers::error( 'pp_too_many_rows', sprintf( 'Would affect %d rows (cap %d). Narrow the where, raise "limit", or force:true.', count( $affected ), $cap ), 400 );
		}
		if ( $dry ) {
			return array( 'dry_run' => true, 'op' => $op, 'table' => $table, 'would_affect' => count( $affected ), 'rows' => $affected );
		}

		if ( 'update' === $op ) {
			if ( empty( $data ) ) {
				return PP_Helpers::error( 'pp_no_data', 'update needs "data".', 400 );
			}
			$n = $wpdb->update( $table, $data, $where );
		} else {
			$n = $wpdb->delete( $table, $where );
		}
		if ( false === $n ) {
			return PP_Helpers::error( 'pp_write_failed', $wpdb->last_error ?: 'write failed', 400 );
		}
		return array( 'op' => $op, 'table' => $table, 'affected' => (int) $n, 'before_image' => $affected );
	}
}
