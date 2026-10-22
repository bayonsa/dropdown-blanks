<?php
/**
 * Pure (WordPress-independent) logic: turns the stored "Jumbled Sentence" data
 * (passage with [code] tokens + per-code options) into a LearnDash cloze text
 * and an ordered list of dropdown choices, one entry per blank.
 *
 * Data shape read from the old question (post meta written by the previous plugin):
 *   _ld_advanced_jumbled_question : string, e.g. "Drivers must [a] the passenger and [b] ..."
 *   _ld_advanced_jumbled_options  : array of [
 *        'code' => 'a', 'vals' => ['greet','ignore',...], 'correct' => 'greet',
 *        'points' => '1', 'pts_for_each_usage' => 'yes'|'no' ]
 *   _ld_advanced_question_blank_option_value  : 'yes' to show an empty first option
 *   _ld_advanced_question_random_option_value : 'yes' to shuffle options
 *
 * @package SERU_Dropdown_Blanks
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'SDDB_TESTING' ) ) {
	exit;
}

class SDDB_Transform {

	/**
	 * Normalise the options meta into a code => option map.
	 *
	 * @param mixed $options Raw meta value.
	 * @return array<string,array>
	 */
	public static function options_by_code( $options ) {
		$map = array();
		if ( ! is_array( $options ) ) {
			return $map;
		}
		foreach ( $options as $opt ) {
			if ( ! is_array( $opt ) || ! isset( $opt['code'] ) ) {
				continue;
			}
			$code = trim( (string) $opt['code'] );
			if ( '' === $code ) {
				continue;
			}
			$vals = array();
			if ( isset( $opt['vals'] ) ) {
				$raw  = is_array( $opt['vals'] ) ? $opt['vals'] : preg_split( '/\r\n|\r|\n|\|/', (string) $opt['vals'] );
				foreach ( $raw as $v ) {
					$v = trim( (string) $v );
					if ( '' !== $v ) {
						$vals[] = $v;
					}
				}
			}
			$map[ $code ] = array(
				'code'               => $code,
				'vals'               => array_values( array_unique( $vals ) ),
				'correct'            => isset( $opt['correct'] ) ? trim( (string) $opt['correct'] ) : '',
				'points'             => isset( $opt['points'] ) ? (float) $opt['points'] : 0.0,
				'pts_for_each_usage' => ( isset( $opt['pts_for_each_usage'] ) && 'yes' === $opt['pts_for_each_usage'] ) ? 'yes' : 'no',
			);
		}
		return $map;
	}

	/**
	 * Build the conversion result.
	 *
	 * @param string $passage   Passage with [code] tokens.
	 * @param mixed  $options   Raw options meta.
	 * @return array{ok:bool, errors:string[], warnings:string[], cloze:string, blanks:array, blank_count:int, total_points:float}
	 */
	public static function build( $passage, $options ) {
		$result = array(
			'ok'          => false,
			'errors'      => array(),
			'warnings'    => array(),
			'cloze'       => '',
			'blanks'      => array(),
			'blank_count' => 0,
			'total_points' => 0,
		);

		$passage = (string) $passage;
		if ( '' === trim( $passage ) ) {
			$result['errors'][] = 'Passage (_ld_advanced_jumbled_question) is empty.';
			return $result;
		}

		$map = self::options_by_code( $options );
		if ( empty( $map ) ) {
			$result['errors'][] = 'No dropdown options (_ld_advanced_jumbled_options) found.';
			return $result;
		}

		// Cloze braces would break LearnDash parsing if they appear in the passage itself.
		if ( false !== strpos( $passage, '{' ) || false !== strpos( $passage, '}' ) ) {
			$result['errors'][] = 'Passage contains { or } characters, which LearnDash reserves for blanks.';
			return $result;
		}

		$codes   = array_keys( $map );
		$quoted  = array_map(
			function ( $c ) {
				return preg_quote( $c, '/' );
			},
			$codes
		);
		$pattern = '/\[(' . implode( '|', $quoted ) . ')\]/';

		// How often each code is used: with pts_for_each_usage = 'yes' the old plugin
		// awarded the option's points once in total, otherwise once per dropdown.
		preg_match_all( $pattern, $passage, $uses );
		$usage = array_count_values( $uses[1] );

		$blanks = array();
		$errors = array();
		$cloze  = preg_replace_callback(
			$pattern,
			function ( $m ) use ( $map, $usage, &$blanks, &$errors ) {
				$opt = $map[ $m[1] ];
				if ( '' === $opt['correct'] ) {
					$errors[] = 'Blank [' . $opt['code'] . '] has no correct answer.';
				}
				if ( ! in_array( $opt['correct'], $opt['vals'], true ) ) {
					// Make sure the correct answer is always selectable.
					$opt['vals'][] = $opt['correct'];
				}
				if ( false !== strpos( $opt['correct'], '[' ) || false !== strpos( $opt['correct'], ']' )
					|| false !== strpos( $opt['correct'], '{' ) || false !== strpos( $opt['correct'], '}' )
					|| false !== strpos( $opt['correct'], '|' ) ) {
					$errors[] = 'Correct answer for [' . $opt['code'] . '] contains reserved characters ([ ] { } |).';
				}
				$pts = $opt['points'] > 0 ? $opt['points'] : 0;
				if ( $pts > 0 && 'yes' === $opt['pts_for_each_usage'] && ! empty( $usage[ $opt['code'] ] ) ) {
					$pts = $pts / $usage[ $opt['code'] ];
				}
				$blanks[] = array(
					'code'   => $opt['code'],
					'vals'   => array_values( $opt['vals'] ),
					'points' => $pts,
				);
				$pts_str = $pts > 0 ? '|' . rtrim( rtrim( number_format( $pts, 2, '.', '' ), '0' ), '.' ) : '';
				return '{[' . $opt['correct'] . ']' . $pts_str . '}';
			},
			$passage
		);

		$used = array_unique( array_column( $blanks, 'code' ) );
		foreach ( $codes as $c ) {
			if ( ! in_array( $c, $used, true ) ) {
				$result['warnings'][] = 'Option [' . $c . '] is defined but never used in the passage.';
			}
		}
		if ( preg_match_all( '/\[([A-Za-z0-9_\-]{1,20})\]/', $cloze, $left ) && ! empty( $left[1] ) ) {
			$result['warnings'][] = 'Unmatched tokens left in passage: [' . implode( '], [', array_unique( $left[1] ) ) . ']';
		}
		if ( empty( $blanks ) ) {
			$errors[] = 'No [code] tokens in the passage matched any option.';
		}

		$result['errors']      = array_values( array_unique( $errors ) );
		$result['cloze']       = $cloze;
		$result['blanks']      = $blanks;
		$result['blank_count'] = count( $blanks );
		$result['total_points'] = array_sum( array_column( $blanks, 'points' ) );
		$result['ok']          = empty( $result['errors'] );
		return $result;
	}
}
