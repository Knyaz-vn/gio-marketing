<?php
/**
 * Material calculation. The server is the single source of truth:
 * the browser only sends dimensions, prices are never taken from the client.
 *
 * @package EcodomQuote
 */

declare(strict_types=1);

namespace EcodomQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Roof and fence estimators.
 */
final class Calculator {

	public const MAX_SLOPES = 20;
	private const MAX_SIDE  = 100.0; // m, sanity limit for one slope side.
	private const MAX_FENCE = 2000.0; // m.

	/**
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	public function __construct( private array $settings ) {}

	/**
	 * Runs the calculation.
	 *
	 * @param array<string, mixed> $model Model data (see Model::from_post()).
	 * @param array<string, mixed> $req   Request: mode, thickness, coating, slopes|fence.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException On invalid input (message is user-facing).
	 */
	public function calculate( array $model, array $req ): array {
		$mode = 'fence' === ( $req['mode'] ?? '' ) ? 'fence' : 'roof';
		if ( ! in_array( $model['type'], Model::types_for_mode( $mode ), true ) ) {
			throw new \InvalidArgumentException( __( 'This model cannot be used in this calculator.', 'ecodom-quote' ) );
		}
		if ( $model['width_total'] <= 0 || $model['width_useful'] <= 0 || $model['width_useful'] > $model['width_total'] ) {
			throw new \InvalidArgumentException( __( 'Model dimensions are not configured. Please contact us.', 'ecodom-quote' ) );
		}

		$thickness = in_array( (string) ( $req['thickness'] ?? '' ), Model::THICKNESSES, true ) ? (string) $req['thickness'] : '';
		$coating   = isset( Model::coatings()[ (string) ( $req['coating'] ?? '' ) ] ) ? (string) $req['coating'] : '';
		$price_sqm = (float) ( $model['prices'][ $thickness ][ $coating ] ?? 0 );
		if ( $price_sqm <= 0 ) {
			throw new \InvalidArgumentException( __( 'Select an available thickness and coating.', 'ecodom-quote' ) );
		}

		$ctx = array(
			'model'     => $model,
			'thickness' => $thickness,
			'coating'   => $coating,
			'price_sqm' => $price_sqm,
			'sheet'     => sprintf(
				/* translators: 1: model, 2: thickness, 3: coating */
				__( 'Sheet %1$s, %2$s mm, %3$s', 'ecodom-quote' ),
				$model['title'],
				$thickness,
				mb_strtolower( Model::coatings()[ $coating ] )
			),
		);

		$result = 'fence' === $mode ? $this->fence( $ctx, (array) ( $req['fence'] ?? array() ) ) : $this->roof( $ctx, (array) ( $req['slopes'] ?? array() ) );

		$total  = 0.0;
		foreach ( $result['positions'] as $pos ) {
			$total += $pos['sum'];
		}
		$spread = max( 0.0, (float) $this->settings['price_spread'] ) / 100;

		return array_merge(
			$result,
			array(
				'mode'        => $mode,
				'model_id'    => $model['id'],
				'model_title' => $model['title'],
				'thickness'   => $thickness,
				'coating'     => $coating,
				'coating_txt' => Model::coatings()[ $coating ],
				'price_sqm'   => $price_sqm,
				'total'       => round( $total, 2 ),
				'min'         => self::round_to( $total * ( 1 - $spread ), 10, 'floor' ),
				'max'         => self::round_to( $total * ( 1 + $spread ), 10, 'ceil' ),
				'currency'    => 'UAH',
			)
		);
	}

	/**
	 * Roof: each slope is a rectangle or an isosceles trapezoid (a triangle is a trapezoid with top = 0).
	 *
	 * @param array<string, mixed> $ctx    Context.
	 * @param array<int, mixed>    $slopes Raw slopes.
	 * @return array<string, mixed>
	 */
	private function roof( array $ctx, array $slopes ): array {
		$model   = $ctx['model'];
		$useful  = $model['width_useful'] / 1000;
		$total_w = $model['width_total'] / 1000;
		$clean   = $this->sanitize_slopes( $slopes );

		$sheets     = array(); // length(mm) => count.
		$roof_area  = 0.0;
		$eave_m     = 0.0;
		$top_m      = 0.0;
		$hips_m     = 0.0;
		$end_m      = 0.0;
		$out_slopes = array();

		foreach ( $clean as $s ) {
			$bottom = $s['a'];
			$top    = 'rect' === $s['shape'] ? $s['a'] : $s['b'];
			$h      = $s['h'];
			$wide   = max( $bottom, $top );
			$d      = ( $wide - min( $bottom, $top ) ) / 2; // Horizontal offset of the slanted side.
			$cols   = (int) ceil( round( $wide / $useful, 6 ) );

			$slope_sheets = 0;
			for ( $i = 0; $i < $cols; $i++ ) {
				// Longest point of the column: the column edge closest to the slope centre.
				$x0     = $i * $useful;
				$x1     = min( $wide, ( $i + 1 ) * $useful );
				$center = $wide / 2;
				$x      = ( $x0 <= $center && $x1 >= $center ) ? $center : ( $x1 < $center ? $x1 : $x0 );
				$col_h  = $this->height_at( $x, $wide, $d, $h );
				if ( $col_h <= 0.001 ) {
					continue;
				}
				foreach ( $this->split_column( $col_h * 1000, $model ) as $len ) {
					$sheets[ $len ] = ( $sheets[ $len ] ?? 0 ) + 1;
					++$slope_sheets;
				}
			}

			$area       = ( $bottom + $top ) / 2 * $h;
			$roof_area += $area;
			$eave_m    += $bottom;
			$top_m     += $top;
			if ( $d > 0 ) {
				$hips_m += sqrt( $d * $d + $h * $h ); // Two sides, each shared by two slopes.
			}
			if ( 'rect' === $s['shape'] ) {
				$end_m += 2 * $h;
			}
			$out_slopes[] = array_merge( $s, array( 'area' => round( $area, 2 ), 'sheets' => $slope_sheets ) );
		}

		$ridge_m = ( count( $clean ) > 1 ? $top_m / 2 : $top_m ) + $hips_m;

		krsort( $sheets );
		$positions  = array();
		$sheet_area = 0.0;
		$sheet_cnt  = 0;
		foreach ( $sheets as $len => $count ) {
			$area        = $count * $total_w * ( $len / 1000 );
			$sheet_area += $area;
			$sheet_cnt  += $count;
			$positions[] = $this->pos(
				'sheet',
				/* translators: 1: sheet name, 2: length in mm */
				sprintf( __( '%1$s, L = %2$d mm', 'ecodom-quote' ), $ctx['sheet'], $len ),
				$count,
				__( 'pcs', 'ecodom-quote' ),
				$ctx['price_sqm'] * $total_w * ( $len / 1000 ),
				round( $area, 2 )
			);
		}

		$piece = max( 0.1, (float) $this->settings['trim_piece_m'] - (float) $this->settings['trim_overlap_m'] );
		$trims = array(
			'ridge' => array( __( 'Ridge / hip trim', 'ecodom-quote' ), $ridge_m, 'price_ridge' ),
			'end'   => array( __( 'End (gable) trim', 'ecodom-quote' ), $end_m, 'price_end' ),
			'eave'  => array( __( 'Eave trim', 'ecodom-quote' ), $eave_m, 'price_eave' ),
		);
		foreach ( $trims as $key => [ $name, $meters, $price_key ] ) {
			if ( $meters <= 0 ) {
				continue;
			}
			$positions[] = $this->pos(
				$key,
				/* translators: 1: trim name, 2: piece length, 3: running meters */
				sprintf( __( '%1$s %2$s m (%3$s r.m.)', 'ecodom-quote' ), $name, Util::num( (float) $this->settings['trim_piece_m'], 1 ), Util::num( $meters, 1 ) ),
				(int) ceil( round( $meters / $piece, 6 ) ),
				__( 'pcs', 'ecodom-quote' ),
				(float) $this->settings[ $price_key ]
			);
		}

		$positions[] = $this->screws( $sheet_area, (float) $this->settings['screws_per_sqm'] );

		return array(
			'positions'   => $positions,
			'slopes'      => $out_slopes,
			'sqm'         => round( $sheet_area, 2 ),
			'useful_sqm'  => round( $roof_area, 2 ),
			'sheet_count' => $sheet_cnt,
			'waste_pct'   => $roof_area > 0 ? round( ( $sheet_area / $roof_area - 1 ) * 100, 1 ) : 0,
			'perimeter'   => array(
				'ridge' => round( $ridge_m, 2 ),
				'end'   => round( $end_m, 2 ),
				'eave'  => round( $eave_m, 2 ),
			),
		);
	}

	/**
	 * Fence: vertical sheets, posts and horizontal rails.
	 *
	 * @param array<string, mixed> $ctx   Context.
	 * @param array<string, mixed> $fence Raw fence input.
	 * @return array<string, mixed>
	 */
	private function fence( array $ctx, array $fence ): array {
		$model  = $ctx['model'];
		$length = Util::to_float( $fence['length'] ?? 0 );
		$height = Util::to_float( $fence['height'] ?? 0 );
		if ( $length <= 0 || $length > self::MAX_FENCE ) {
			throw new \InvalidArgumentException( __( 'Enter the fence length.', 'ecodom-quote' ) );
		}
		if ( $height <= 0 || $height > 6 ) {
			throw new \InvalidArgumentException( __( 'Enter the fence height.', 'ecodom-quote' ) );
		}

		$post_types = Settings::post_types();
		$post       = null;
		foreach ( $post_types as $pt ) {
			if ( $pt['id'] === ( $fence['post'] ?? '' ) ) {
				$post = $pt;
			}
		}
		$post ??= $post_types[0] ?? array(
			'id'    => '',
			'name'  => __( 'Post', 'ecodom-quote' ),
			'price' => 0.0,
		);

		$len_mm = (int) ( ceil( $height * 100 - 1e-6 ) * 10 ); // Round up to 10 mm.
		if ( $model['length_max'] > 0 && $len_mm > $model['length_max'] ) {
			/* translators: %d: max length mm */
			throw new \InvalidArgumentException( sprintf( __( 'Maximum sheet length for this model is %d mm.', 'ecodom-quote' ), $model['length_max'] ) );
		}
		$len_mm   = max( $len_mm, $model['length_min'] );
		$count    = (int) ceil( round( $length * 1000 / $model['width_useful'], 6 ) );
		$area     = $count * ( $model['width_total'] / 1000 ) * ( $len_mm / 1000 );
		$step     = max( 0.5, (float) $this->settings['fence_post_step'] );
		$posts    = (int) ceil( round( $length / $step, 6 ) ) + 1;
		$rails    = $height > (float) $this->settings['fence_rails_limit'] ? 3 : 2;
		$rail_m   = (int) ceil( $rails * $length );

		$positions = array(
			$this->pos(
				'sheet',
				/* translators: 1: sheet name, 2: length in mm */
				sprintf( __( '%1$s, L = %2$d mm', 'ecodom-quote' ), $ctx['sheet'], $len_mm ),
				$count,
				__( 'pcs', 'ecodom-quote' ),
				$ctx['price_sqm'] * ( $model['width_total'] / 1000 ) * ( $len_mm / 1000 ),
				round( $area, 2 )
			),
			$this->pos( 'posts', $post['name'], $posts, __( 'pcs', 'ecodom-quote' ), $post['price'] ),
			$this->pos(
				'rails',
				/* translators: %d: number of rails */
				sprintf( _n( 'Rail (cross bar), %d row', 'Rail (cross bar), %d rows', $rails, 'ecodom-quote' ), $rails ),
				$rail_m,
				__( 'm', 'ecodom-quote' ),
				(float) $this->settings['fence_rail_price']
			),
			$this->screws( $area, (float) $this->settings['screws_per_sqm_fence'] ),
		);

		return array(
			'positions'   => $positions,
			'fence'       => array(
				'length'    => $length,
				'height'    => $height,
				'post'      => $post['name'],
				'post_id'   => $post['id'],
				'posts'     => $posts,
				'rails'     => $rails,
				'post_step' => $step,
			),
			'sqm'         => round( $area, 2 ),
			'useful_sqm'  => round( $length * $height, 2 ),
			'sheet_count' => $count,
		);
	}

	/**
	 * @param array<int, mixed> $slopes Raw.
	 * @return list<array{shape:string,a:float,b:float,h:float}>
	 */
	private function sanitize_slopes( array $slopes ): array {
		$out = array();
		foreach ( array_slice( array_values( $slopes ), 0, self::MAX_SLOPES ) as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$shape = 'trap' === ( $s['shape'] ?? '' ) ? 'trap' : 'rect';
			$a     = Util::to_float( $s['a'] ?? 0 );
			$b     = 'trap' === $shape ? Util::to_float( $s['b'] ?? 0 ) : $a;
			$h     = Util::to_float( $s['h'] ?? 0 );
			if ( $a < 0 || $b < 0 || $h <= 0 || max( $a, $b ) <= 0 || max( $a, $b, $h ) > self::MAX_SIDE ) {
				throw new \InvalidArgumentException(
					/* translators: %d: slope number */
					sprintf( __( 'Check the dimensions of slope #%d.', 'ecodom-quote' ), count( $out ) + 1 )
				);
			}
			$out[] = array(
				'shape' => $shape,
				'a'     => round( $a, 3 ),
				'b'     => round( $b, 3 ),
				'h'     => round( $h, 3 ),
			);
		}
		if ( ! $out ) {
			throw new \InvalidArgumentException( __( 'Add at least one roof slope.', 'ecodom-quote' ) );
		}
		return $out;
	}

	/**
	 * Slope height at horizontal position x for an isosceles trapezoid of width $wide.
	 */
	private function height_at( float $x, float $wide, float $d, float $h ): float {
		if ( $d <= 0 ) {
			return $h;
		}
		if ( $x < $d ) {
			return $h * $x / $d;
		}
		if ( $x > $wide - $d ) {
			return $h * ( $wide - $x ) / $d;
		}
		return $h;
	}

	/**
	 * Splits a column of length $need (mm) into sheets honoring max length, overlap and wave step.
	 *
	 * @param array<string, mixed> $model Model.
	 * @return list<int> Sheet lengths in mm.
	 */
	public function split_column( float $need, array $model ): array {
		$min     = max( 0, (int) $model['length_min'] );
		$max     = (int) $model['length_max'] > 0 ? (int) $model['length_max'] : 12000;
		$overlap = max( 0.0, (float) $this->settings['roof_overlap_mm'] );
		// Metal tile is cut on a whole number of waves; corrugated sheet is cut to 10 mm.
		$step = ( 'roof' === $model['type'] && (int) $model['wave_step'] > 0 ) ? (int) $model['wave_step'] : 10;

		for ( $rows = 1; $rows <= 50; $rows++ ) {
			$len = ( $need + ( $rows - 1 ) * $overlap ) / $rows;
			$len = (int) ( ceil( round( $len / $step, 6 ) ) * $step );
			$len = max( $len, $min );
			if ( $len <= $max ) {
				return array_fill( 0, $rows, $len );
			}
		}
		throw new \InvalidArgumentException( __( 'The slope is too long for this model.', 'ecodom-quote' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function screws( float $area, float $per_sqm ): array {
		return $this->pos(
			'screws',
			__( 'Roofing screws with EPDM washer', 'ecodom-quote' ),
			(int) ceil( round( $area * $per_sqm, 6 ) ),
			__( 'pcs', 'ecodom-quote' ),
			(float) $this->settings['price_screw']
		);
	}

	/**
	 * Builds a position row.
	 *
	 * @return array<string, mixed>
	 */
	private function pos( string $key, string $name, int $qty, string $unit, float $price, ?float $sqm = null ): array {
		return array(
			'key'   => $key,
			'name'  => $name,
			'qty'   => $qty,
			'unit'  => $unit,
			'price' => round( $price, 2 ),
			'sum'   => round( $qty * $price, 2 ),
			'sqm'   => $sqm,
		);
	}

	private static function round_to( float $value, int $to, string $fn ): float {
		return (float) ( 'floor' === $fn ? floor( $value / $to ) : ceil( $value / $to ) ) * $to;
	}
}
