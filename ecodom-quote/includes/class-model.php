<?php
/**
 * Model repository (ecodom_model posts).
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes model meta as a normalized array.
 *
 * @phpstan-type ModelData array{id:int,title:string,slug:string,type:string,width_total:int,width_useful:int,wave_step:int,length_min:int,length_max:int,prices:array<string,array<string,float>>}
 */
final class Model {

	public const THICKNESSES = array( '0.40', '0.45', '0.50' );
	public const META_PREFIX = '_edq_';
	private const DIM_KEYS   = array( 'width_total', 'width_useful', 'wave_step', 'length_min', 'length_max' );

	/**
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			'roof'       => __( 'Metal roof tile', 'ecodom-quote' ),
			'profnastyl' => __( 'Corrugated sheet (roof and fence)', 'ecodom-quote' ),
			'fence'      => __( 'Fence sheet', 'ecodom-quote' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function coatings(): array {
		return array(
			'gloss' => __( 'Gloss', 'ecodom-quote' ),
			'matt'  => __( 'Matt', 'ecodom-quote' ),
		);
	}

	/**
	 * Which model types may be used in a calculator mode.
	 *
	 * @param string $mode roof|fence.
	 * @return list<string>
	 */
	public static function types_for_mode( string $mode ): array {
		return 'fence' === $mode ? array( 'fence', 'profnastyl' ) : array( 'roof', 'profnastyl' );
	}

	/**
	 * @return ModelData|null
	 */
	public static function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || Post_Types::MODEL !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		return self::from_post( $post );
	}

	/**
	 * Finds a published model by ID or slug.
	 *
	 * @return ModelData|null
	 */
	public static function find( string $id_or_slug ): ?array {
		$id_or_slug = trim( $id_or_slug );
		if ( '' === $id_or_slug ) {
			return null;
		}
		if ( ctype_digit( $id_or_slug ) ) {
			return self::get( (int) $id_or_slug );
		}
		$post = get_page_by_path( sanitize_title( $id_or_slug ), OBJECT, Post_Types::MODEL );
		return $post instanceof \WP_Post ? self::get( $post->ID ) : null;
	}

	/**
	 * @return ModelData
	 */
	public static function from_post( \WP_Post $post ): array {
		$data = array(
			'id'    => (int) $post->ID,
			'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'slug'  => $post->post_name,
			'type'  => (string) get_post_meta( $post->ID, self::META_PREFIX . 'type', true ),
		);
		if ( ! isset( self::types()[ $data['type'] ] ) ) {
			$data['type'] = 'roof';
		}
		foreach ( self::DIM_KEYS as $key ) {
			$data[ $key ] = (int) get_post_meta( $post->ID, self::META_PREFIX . $key, true );
		}
		$data['prices'] = self::normalize_prices( get_post_meta( $post->ID, self::META_PREFIX . 'prices', true ) );
		return $data;
	}

	/**
	 * @param mixed $raw Raw prices.
	 * @return array<string, array<string, float>>
	 */
	public static function normalize_prices( mixed $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( self::THICKNESSES as $t ) {
			foreach ( array_keys( self::coatings() ) as $c ) {
				$out[ $t ][ $c ] = round( max( 0.0, Util::to_float( $raw[ $t ][ $c ] ?? 0 ) ), 2 );
			}
		}
		return $out;
	}

	/**
	 * Sanitizes and stores meta.
	 *
	 * @param array<string, mixed> $raw Unslashed raw input.
	 */
	public static function save_meta( int $post_id, array $raw ): void {
		$type = sanitize_key( (string) ( $raw['type'] ?? 'roof' ) );
		update_post_meta( $post_id, self::META_PREFIX . 'type', isset( self::types()[ $type ] ) ? $type : 'roof' );
		foreach ( self::DIM_KEYS as $key ) {
			update_post_meta( $post_id, self::META_PREFIX . $key, max( 0, (int) round( Util::to_float( $raw[ $key ] ?? 0 ) ) ) );
		}
		update_post_meta( $post_id, self::META_PREFIX . 'prices', self::normalize_prices( $raw['prices'] ?? array() ) );
	}

	/**
	 * Published models usable in a mode.
	 *
	 * @param string|null $mode roof|fence or null for all.
	 * @return list<ModelData>
	 */
	public static function all( ?string $mode = null ): array {
		$args = array(
			'post_type'      => Post_Types::MODEL,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'no_found_rows'  => true,
		);
		if ( $mode ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_PREFIX . 'type',
					'value'   => self::types_for_mode( $mode ),
					'compare' => 'IN',
				),
			);
		}
		$out = array();
		foreach ( get_posts( $args ) as $post ) {
			$out[] = self::from_post( $post );
		}
		return $out;
	}

	/**
	 * Data safe to expose to the browser.
	 *
	 * @param ModelData $m Model.
	 * @return array<string, mixed>
	 */
	public static function to_public( array $m ): array {
		$variants = array();
		foreach ( $m['prices'] as $t => $by_coating ) {
			foreach ( $by_coating as $c => $price ) {
				if ( $price > 0 ) {
					$variants[ $t ][] = $c;
				}
			}
		}
		return array(
			'id'       => $m['id'],
			'title'    => $m['title'],
			'type'     => $m['type'],
			'variants' => $variants,
		);
	}
}
