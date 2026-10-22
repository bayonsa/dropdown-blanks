<?php
/**
 * Front end: a tiny read-only REST endpoint that returns the dropdown choices
 * for converted questions, and the script that turns cloze inputs into dropdowns.
 *
 * Only the visible choices are exposed (never which one is correct).
 *
 * @package SERU_Dropdown_Blanks
 */

defined( 'ABSPATH' ) || exit;

class SDDB_Frontend {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function register_route() {
		register_rest_route(
			'sddb/v1',
			'/blanks',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'ids' => array(
						'required'          => true,
						'sanitize_callback' => function ( $v ) {
							return array_slice( array_filter( array_map( 'absint', explode( ',', (string) $v ) ) ), 0, 200 );
						},
					),
				),
				'callback'            => array( __CLASS__, 'rest_blanks' ),
			)
		);
	}

	/**
	 * Map LearnDash pro-quiz question ids (what the quiz markup exposes) to dropdown configs.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function rest_blanks( $req ) {
		$pro_ids = (array) $req->get_param( 'ids' );
		$out     = array();
		if ( empty( $pro_ids ) ) {
			return rest_ensure_response( $out );
		}
		$posts = get_posts(
			array(
				'post_type'      => 'sfwd-question',
				'post_status'    => 'publish',
				'posts_per_page' => count( $pro_ids ),
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => 'question_pro_id',
						'value'   => $pro_ids,
						'compare' => 'IN',
					),
					array(
						'key'     => SDDB_Converter::META_BLANKS,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( $posts as $post_id ) {
			$cfg = get_post_meta( $post_id, SDDB_Converter::META_BLANKS, true );
			$pro = (int) get_post_meta( $post_id, 'question_pro_id', true );
			if ( ! $pro || empty( $cfg['blanks'] ) ) {
				continue;
			}
			$out[ $pro ] = array(
				'shuffle'     => ! empty( $cfg['shuffle'] ),
				'blank_first' => ! empty( $cfg['blank_first'] ),
				'blanks'      => array_map(
					function ( $b ) {
						// Strip placeholders also for questions converted by older versions.
						return array_values(
							array_filter(
								(array) $b['vals'],
								function ( $v ) {
									return ! SDDB_Transform::is_placeholder( $v );
								}
							)
						);
					},
					$cfg['blanks']
				),
				'placeholders' => array_map(
					function ( $b ) {
						if ( ! empty( $b['placeholder'] ) ) {
							return $b['placeholder'];
						}
						foreach ( (array) $b['vals'] as $v ) {
							if ( SDDB_Transform::is_placeholder( $v ) ) {
								return $v;
							}
						}
						return '';
					},
					$cfg['blanks']
				),
			);
		}
		$res = rest_ensure_response( $out );
		$res->header( 'Cache-Control', 'public, max-age=300' );
		return $res;
	}

	public static function enqueue() {
		wp_enqueue_script(
			'sddb-dropdown-blanks',
			SDDB_URL . 'dropdown-blanks.js',
			array( 'jquery' ),
			SDDB_VERSION,
			true
		);
		wp_localize_script(
			'sddb-dropdown-blanks',
			'SDDB',
			array(
				'endpoint'    => esc_url_raw( rest_url( 'sddb/v1/blanks' ) ),
				'placeholder' => __( 'Select…', 'seru-dropdown-blanks' ),
			)
		);
		wp_enqueue_style( 'sddb-dropdown-blanks', SDDB_URL . 'dropdown-blanks.css', array(), SDDB_VERSION );
	}
}
