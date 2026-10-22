<?php
/**
 * Converts "Jumbled Sentence" questions into LearnDash core "Fill in the blank"
 * (cloze) questions, keeping a full backup so every change can be reverted.
 *
 * @package SERU_Dropdown_Blanks
 */

defined( 'ABSPATH' ) || exit;

class SDDB_Converter {

	const OLD_TYPE    = 'laq_jumbled_sentence';
	const NEW_TYPE    = 'cloze_answer';
	const META_BLANKS = '_sddb_blanks';      // Dropdown config used on the front end.
	const META_BACKUP = '_sddb_backup';      // Original type, points and answer data.
	const META_FLAG   = '_sddb_converted';   // Timestamp of conversion.

	/**
	 * LearnDash question post ids that still use the old type.
	 *
	 * @return int[]
	 */
	public static function pending_ids() {
		return get_posts(
			array(
				'post_type'      => 'sfwd-question',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array(
						'key'     => '_ld_advanced_jumbled_question',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_FLAG,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
	}

	/**
	 * Question post ids converted by this plugin.
	 *
	 * @return int[]
	 */
	public static function converted_ids() {
		return get_posts(
			array(
				'post_type'      => 'sfwd-question',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	/**
	 * Dry run for one question: what would the cloze text look like?
	 *
	 * @param int $post_id Question post id.
	 * @return array
	 */
	public static function preview( $post_id ) {
		$passage = get_post_meta( $post_id, '_ld_advanced_jumbled_question', true );
		$options = get_post_meta( $post_id, '_ld_advanced_jumbled_options', true );
		$res     = SDDB_Transform::build( $passage, $options );
		$res['post_id']  = (int) $post_id;
		$res['title']    = get_the_title( $post_id );
		$res['pro_id']   = (int) get_post_meta( $post_id, 'question_pro_id', true );
		$res['shuffle']  = 'yes' === get_post_meta( $post_id, '_ld_advanced_question_random_option_value', true );
		$res['blank_first'] = 'yes' === get_post_meta( $post_id, '_ld_advanced_question_blank_option_value', true );
		if ( ! $res['pro_id'] ) {
			$res['ok']       = false;
			$res['errors'][] = 'Missing question_pro_id meta (LearnDash link to its quiz question row).';
		}
		return $res;
	}

	/**
	 * Convert one question.
	 *
	 * @param int $post_id Question post id.
	 * @return true|WP_Error
	 */
	public static function convert( $post_id ) {
		if ( ! class_exists( 'WpProQuiz_Model_QuestionMapper' ) || ! class_exists( 'WpProQuiz_Model_AnswerTypes' ) ) {
			return new WP_Error( 'sddb_no_ld', 'LearnDash is not active.' );
		}
		if ( get_post_meta( $post_id, self::META_FLAG, true ) ) {
			return new WP_Error( 'sddb_done', 'Already converted.' );
		}

		$prev = self::preview( $post_id );
		if ( ! $prev['ok'] ) {
			return new WP_Error( 'sddb_invalid', implode( ' ', $prev['errors'] ) );
		}

		$mapper   = new WpProQuiz_Model_QuestionMapper();
		$question = $mapper->fetch( $prev['pro_id'] );
		if ( ! $question || ! $question->getId() ) {
			return new WP_Error( 'sddb_missing', 'LearnDash question row ' . $prev['pro_id'] . ' not found.' );
		}
		if ( self::OLD_TYPE !== $question->getAnswerType() ) {
			return new WP_Error( 'sddb_type', 'Question type is "' . $question->getAnswerType() . '", not ' . self::OLD_TYPE . '.' );
		}

		// Full backup first, so revert() can restore the exact previous state.
		$backup = array(
			'answer_type'            => $question->getAnswerType(),
			'answer_data'            => maybe_serialize( $question->getAnswerData() ),
			'points'                 => $question->getPoints(),
			'answer_points_activated'=> $question->isAnswerPointsActivated(),
			'question_type_meta'     => get_post_meta( $post_id, 'question_type', true ),
			'time'                   => current_time( 'mysql' ),
		);
		update_post_meta( $post_id, self::META_BACKUP, $backup );

		$per_blank = $prev['total_points'] > 0;

		$answer = new WpProQuiz_Model_AnswerTypes();
		$answer->setAnswer( $prev['cloze'] );
		$answer->setHtml( true );
		$answer->setCorrect( true );
		$answer->setPoints( $per_blank ? $prev['total_points'] : $question->getPoints() );

		$question->setAnswerType( self::NEW_TYPE );
		$question->setAnswerData( array( $answer ) );
		if ( $per_blank ) {
			$question->setAnswerPointsActivated( true );
			$question->setPoints( $prev['total_points'] );
		}
		$mapper->save( $question );

		update_post_meta( $post_id, 'question_type', self::NEW_TYPE );
		update_post_meta(
			$post_id,
			self::META_BLANKS,
			array(
				'blanks'      => $prev['blanks'],
				'shuffle'     => $prev['shuffle'],
				'blank_first' => $prev['blank_first'],
			)
		);
		update_post_meta( $post_id, self::META_FLAG, time() );

		/**
		 * Lets other code react (e.g. clear caches) after a conversion.
		 */
		do_action( 'sddb_converted', $post_id, $prev );
		return true;
	}

	/**
	 * Restore a converted question to exactly what it was before.
	 *
	 * @param int $post_id Question post id.
	 * @return true|WP_Error
	 */
	public static function revert( $post_id ) {
		$backup = get_post_meta( $post_id, self::META_BACKUP, true );
		if ( empty( $backup ) || ! is_array( $backup ) ) {
			return new WP_Error( 'sddb_no_backup', 'No backup stored for this question.' );
		}
		$pro_id   = (int) get_post_meta( $post_id, 'question_pro_id', true );
		$mapper   = new WpProQuiz_Model_QuestionMapper();
		$question = $mapper->fetch( $pro_id );
		if ( ! $question || ! $question->getId() ) {
			return new WP_Error( 'sddb_missing', 'LearnDash question row ' . $pro_id . ' not found.' );
		}
		$question->setAnswerType( $backup['answer_type'] );
		$question->setAnswerData( maybe_unserialize( $backup['answer_data'] ) );
		$question->setPoints( $backup['points'] );
		$question->setAnswerPointsActivated( (bool) $backup['answer_points_activated'] );
		$mapper->save( $question );

		if ( '' !== (string) $backup['question_type_meta'] ) {
			update_post_meta( $post_id, 'question_type', $backup['question_type_meta'] );
		} else {
			delete_post_meta( $post_id, 'question_type' );
		}
		delete_post_meta( $post_id, self::META_BLANKS );
		delete_post_meta( $post_id, self::META_FLAG );
		// Backup is kept on purpose (harmless, and useful for audits).
		return true;
	}

	/**
	 * Read-only check of a converted question: LearnDash data vs. the original data.
	 *
	 * @param int $post_id Question post id.
	 * @return array{ok:bool, problems:string[], blanks:int, answers:string[]}
	 */
	public static function verify( $post_id ) {
		$out    = array( 'ok' => false, 'problems' => array(), 'blanks' => 0, 'answers' => array() );
		$pro_id = (int) get_post_meta( $post_id, 'question_pro_id', true );
		if ( ! class_exists( 'WpProQuiz_Model_QuestionMapper' ) ) {
			$out['problems'][] = 'LearnDash not active.';
			return $out;
		}
		$mapper   = new WpProQuiz_Model_QuestionMapper();
		$question = $mapper->fetch( $pro_id );
		if ( ! $question || ! $question->getId() ) {
			$out['problems'][] = 'LearnDash question row ' . $pro_id . ' not found.';
			return $out;
		}
		if ( self::NEW_TYPE !== $question->getAnswerType() ) {
			$out['problems'][] = 'Type is ' . $question->getAnswerType() . ', expected ' . self::NEW_TYPE . '.';
		}
		$data  = $question->getAnswerData();
		$cloze = ( is_array( $data ) && isset( $data[0] ) && is_object( $data[0] ) ) ? (string) $data[0]->getAnswer() : '';
		if ( '' === $cloze ) {
			$out['problems'][] = 'No cloze text stored.';
			return $out;
		}
		$expected = SDDB_Transform::build(
			get_post_meta( $post_id, '_ld_advanced_jumbled_question', true ),
			get_post_meta( $post_id, '_ld_advanced_jumbled_options', true )
		);
		if ( $expected['ok'] && $expected['cloze'] !== $cloze ) {
			$out['problems'][] = 'Stored text differs from the original passage.';
		}
		preg_match_all( '/\{\[([^\]]*)\](?:\|[0-9.]+)?\}/', $cloze, $m );
		$answers        = $m[1];
		$out['blanks']  = count( $answers );
		$out['answers'] = $answers;
		$cfg            = get_post_meta( $post_id, self::META_BLANKS, true );
		$blanks         = isset( $cfg['blanks'] ) ? $cfg['blanks'] : array();
		if ( count( $blanks ) !== count( $answers ) ) {
			$out['problems'][] = 'Dropdowns (' . count( $blanks ) . ') and blanks (' . count( $answers ) . ') differ.';
		}
		foreach ( $answers as $i => $ans ) {
			$vals = isset( $blanks[ $i ]['vals'] ) ? array_filter( (array) $blanks[ $i ]['vals'], function ( $v ) { return ! SDDB_Transform::is_placeholder( $v ); } ) : array();
			if ( ! in_array( $ans, $vals, true ) ) {
				$out['problems'][] = 'Blank ' . ( $i + 1 ) . ': correct answer "' . $ans . '" is not in its dropdown.';
			}
			if ( SDDB_Transform::is_placeholder( $ans ) ) {
				$out['problems'][] = 'Blank ' . ( $i + 1 ) . ': correct answer is a placeholder.';
			}
		}
		$out['ok'] = empty( $out['problems'] );
		return $out;
	}
}
