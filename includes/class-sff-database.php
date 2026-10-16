<?php
/**
 * Database layer for feedback records.
 *
 * @package SmartFAQFeedback
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the feedback table and provides prepared-query helpers.
 *
 * Reaction values: 1 = like, -1 = dislike. "No reaction" means no row.
 */
class SFF_Database {

	const OPTION_DB_VERSION = 'sff_db_version';

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sff_feedback';
	}

	/**
	 * Create or upgrade the table (idempotent, uses dbDelta).
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  faq_id bigint(20) unsigned NOT NULL,
  voter_hash char(64) NOT NULL,
  reaction tinyint(1) NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY faq_voter (faq_id,voter_hash),
  KEY faq_reaction (faq_id,reaction)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::OPTION_DB_VERSION, SFF_DB_VERSION, false );
	}

	/**
	 * Run install() when the stored schema version differs from the code.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_DB_VERSION ) !== SFF_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Build a "%d,%d,%d" placeholder list.
	 *
	 * @param array $ids IDs.
	 * @return string
	 */
	private static function placeholders( array $ids ) {
		return implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	}

	/**
	 * Normalise a list of IDs to unique positive integers.
	 *
	 * @param array $ids Raw IDs.
	 * @return int[]
	 */
	private static function clean_ids( $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		return $ids;
	}

	/**
	 * Like / dislike totals for several FAQs.
	 *
	 * @param array $ids FAQ IDs.
	 * @return array<int,array{likes:int,dislikes:int}> Keyed by FAQ ID (every requested ID present).
	 */
	public static function get_counts( $ids ) {
		global $wpdb;

		$ids    = self::clean_ids( $ids );
		$result = array();
		foreach ( $ids as $id ) {
			$result[ $id ] = array(
				'likes'    => 0,
				'dislikes' => 0,
			);
		}
		if ( empty( $ids ) ) {
			return $result;
		}

		$table = self::table();
		$sql   = "SELECT faq_id,
				SUM( CASE WHEN reaction = 1 THEN 1 ELSE 0 END ) AS likes,
				SUM( CASE WHEN reaction = -1 THEN 1 ELSE 0 END ) AS dislikes
			FROM {$table}
			WHERE faq_id IN ( " . self::placeholders( $ids ) . ' )
			GROUP BY faq_id';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name and placeholder list are internal.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ) );
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$result[ (int) $row->faq_id ] = array(
					'likes'    => (int) $row->likes,
					'dislikes' => (int) $row->dislikes,
				);
			}
		}
		return $result;
	}

	/**
	 * A voter's reactions for several FAQs.
	 *
	 * @param array  $ids        FAQ IDs.
	 * @param string $voter_hash Voter hash.
	 * @return array<int,int> FAQ ID => 1 or -1 (only FAQs with a reaction).
	 */
	public static function get_reactions( $ids, $voter_hash ) {
		global $wpdb;

		$ids = self::clean_ids( $ids );
		if ( empty( $ids ) || ! is_string( $voter_hash ) || '' === $voter_hash ) {
			return array();
		}

		$table = self::table();
		$sql   = "SELECT faq_id, reaction FROM {$table}
			WHERE voter_hash = %s AND faq_id IN ( " . self::placeholders( $ids ) . ' )';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name and placeholder list are internal.
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $voter_hash ), $ids ) ) );
		$result = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$result[ (int) $row->faq_id ] = (int) $row->reaction;
			}
		}
		return $result;
	}

	/**
	 * One voter's reaction for one FAQ.
	 *
	 * @param int    $faq_id     FAQ ID.
	 * @param string $voter_hash Voter hash.
	 * @return int 1, -1 or 0.
	 */
	public static function get_reaction( $faq_id, $voter_hash ) {
		$reactions = self::get_reactions( array( $faq_id ), $voter_hash );
		return isset( $reactions[ (int) $faq_id ] ) ? $reactions[ (int) $faq_id ] : 0;
	}

	/**
	 * Store (insert or change) a reaction. The UNIQUE key guarantees one row per voter per FAQ.
	 *
	 * @param int    $faq_id     FAQ ID.
	 * @param string $voter_hash Voter hash.
	 * @param int    $reaction   1 or -1.
	 * @return bool
	 */
	public static function save_reaction( $faq_id, $voter_hash, $reaction ) {
		global $wpdb;

		$reaction = ( 1 === (int) $reaction ) ? 1 : -1;
		$now      = current_time( 'mysql', true );
		$table    = self::table();

		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				"INSERT INTO {$table} ( faq_id, voter_hash, reaction, created_at, updated_at )
				VALUES ( %d, %s, %d, %s, %s )
				ON DUPLICATE KEY UPDATE reaction = VALUES( reaction ), updated_at = VALUES( updated_at )",
				absint( $faq_id ),
				$voter_hash,
				$reaction,
				$now,
				$now
			)
		);
		return false !== $result;
	}

	/**
	 * Remove one voter's reaction on one FAQ.
	 *
	 * @param int    $faq_id     FAQ ID.
	 * @param string $voter_hash Voter hash.
	 * @return bool
	 */
	public static function remove_reaction( $faq_id, $voter_hash ) {
		global $wpdb;
		$result = $wpdb->delete(
			self::table(),
			array(
				'faq_id'     => absint( $faq_id ),
				'voter_hash' => $voter_hash,
			),
			array( '%d', '%s' )
		);
		return false !== $result;
	}

	/**
	 * Delete all feedback for a FAQ (used only when that FAQ is permanently deleted).
	 *
	 * @param int $faq_id FAQ ID.
	 */
	public static function delete_for_faq( $faq_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'faq_id' => absint( $faq_id ) ), array( '%d' ) );
	}
}
