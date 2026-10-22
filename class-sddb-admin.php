<?php
/**
 * Admin screen: Tools → SERU Dropdown Blanks.
 * Preview every question, convert one or all, and revert at any time.
 *
 * @package SERU_Dropdown_Blanks
 */

defined( 'ABSPATH' ) || exit;

class SDDB_Admin {

	const SLUG = 'seru-dropdown-blanks';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_sddb_action', array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_management_page( 'SERU Dropdown Blanks', 'SERU Dropdown Blanks', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	private static function action_url( $do, $id = 0 ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'sddb_action',
					'do'     => $do,
					'id'     => (int) $id,
				),
				admin_url( 'admin-post.php' )
			),
			'sddb_' . $do . '_' . (int) $id
		);
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'sddb_' . $do . '_' . $id );

		$ok = 0;
		$failed = array();
		switch ( $do ) {
			case 'convert':
				$r = SDDB_Converter::convert( $id );
				if ( is_wp_error( $r ) ) { $failed[ $id ] = $r->get_error_message(); } else { $ok++; }
				break;
			case 'revert':
				$r = SDDB_Converter::revert( $id );
				if ( is_wp_error( $r ) ) { $failed[ $id ] = $r->get_error_message(); } else { $ok++; }
				break;
			case 'convert_all':
				foreach ( SDDB_Converter::pending_ids() as $qid ) {
					$r = SDDB_Converter::convert( $qid );
					if ( is_wp_error( $r ) ) { $failed[ $qid ] = $r->get_error_message(); } else { $ok++; }
				}
				break;
			case 'revert_all':
				foreach ( SDDB_Converter::converted_ids() as $qid ) {
					$r = SDDB_Converter::revert( $qid );
					if ( is_wp_error( $r ) ) { $failed[ $qid ] = $r->get_error_message(); } else { $ok++; }
				}
				break;
		}
		set_transient( 'sddb_notice_' . get_current_user_id(), array( 'do' => $do, 'ok' => $ok, 'failed' => $failed ), 120 );
		wp_safe_redirect( self::url() );
		exit;
	}

	private static function highlight( $cloze ) {
		return preg_replace( '/\{\[([^\]]*)\](?:\|[0-9.]+)?\}/', '<mark>$1</mark>', wp_kses_post( $cloze ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$pending   = SDDB_Converter::pending_ids();
		$converted = SDDB_Converter::converted_ids();
		$notice    = get_transient( 'sddb_notice_' . get_current_user_id() );
		delete_transient( 'sddb_notice_' . get_current_user_id() );
		$ld_ok = class_exists( 'WpProQuiz_Model_QuestionMapper' );
		?>
		<div class="wrap">
			<h1>SERU Dropdown Blanks <small style="font-size:12px;color:#777">v<?php echo esc_html( SDDB_VERSION ); ?></small></h1>
			<p>Turns the old “Jumbled Sentence” questions into LearnDash’s own <em>Fill in the blank</em> type and shows each blank as a dropdown. Every conversion keeps a backup and can be reverted.</p>
			<?php if ( ! $ld_ok ) : ?>
				<div class="notice notice-error"><p>LearnDash is not active. Nothing can be converted.</p></div>
			<?php endif; ?>
			<?php if ( $notice ) : ?>
				<div class="notice <?php echo empty( $notice['failed'] ) ? 'notice-success' : 'notice-warning'; ?>"><p>
					<?php echo esc_html( ucfirst( str_replace( '_', ' ', $notice['do'] ) ) . ': ' . $notice['ok'] . ' done, ' . count( $notice['failed'] ) . ' failed.' ); ?>
				</p>
				<?php if ( ! empty( $notice['failed'] ) ) : ?>
					<ul style="margin-left:1.5em;list-style:disc">
					<?php foreach ( $notice['failed'] as $fid => $msg ) : ?>
						<li><?php echo esc_html( '#' . $fid . ' ' . get_the_title( $fid ) . ': ' . $msg ); ?></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				</div>
			<?php endif; ?>

			<p>
				<strong><?php echo count( $pending ); ?></strong> waiting ·
				<strong><?php echo count( $converted ); ?></strong> converted
			</p>
			<p>
				<?php if ( $pending && $ld_ok ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( self::action_url( 'convert_all' ) ); ?>" onclick="return confirm('Convert all valid questions? Make sure you have a fresh database backup.');">Convert all valid</a>
				<?php endif; ?>
				<?php if ( $converted ) : ?>
					<a class="button" href="<?php echo esc_url( self::action_url( 'revert_all' ) ); ?>" onclick="return confirm('Revert ALL converted questions to their original state?');">Revert all</a>
				<?php endif; ?>
			</p>

			<h2>Waiting</h2>
			<?php if ( ! $pending ) : ?>
				<p>Nothing left to convert.</p>
			<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th style="width:60px">ID</th><th>Question</th><th>Preview (correct answers highlighted)</th><th style="width:70px">Blanks</th><th style="width:120px"></th></tr></thead>
				<tbody>
				<?php foreach ( $pending as $qid ) :
					$p = SDDB_Converter::preview( $qid ); ?>
					<tr>
						<td><?php echo (int) $qid; ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $qid ) ); ?>"><?php echo esc_html( $p['title'] ); ?></a></td>
						<td>
							<?php if ( $p['ok'] ) : ?>
								<div style="max-height:9em;overflow:auto"><?php echo wp_kses_post( self::highlight( $p['cloze'] ) ); ?></div>
							<?php else : ?>
								<span style="color:#b32d2e"><?php echo esc_html( implode( ' ', $p['errors'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $p['warnings'] ) ) : ?>
								<div style="color:#996800"><?php echo esc_html( implode( ' ', $p['warnings'] ) ); ?></div>
							<?php endif; ?>
						</td>
						<td><?php echo (int) $p['blank_count']; ?></td>
						<td>
							<?php if ( $p['ok'] && $ld_ok ) : ?>
								<a class="button button-small" href="<?php echo esc_url( self::action_url( 'convert', $qid ) ); ?>">Convert</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<h2 style="margin-top:2em">Converted</h2>
			<p id="sddb-verify-summary"></p>
			<?php if ( ! $converted ) : ?>
				<p>None yet.</p>
			<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th style="width:60px">ID</th><th>Question</th><th>Check (correct answers)</th><th style="width:160px">Converted</th><th style="width:120px"></th></tr></thead>
				<tbody>
				<?php
				$bad = 0;
				foreach ( $converted as $qid ) :
					$v = SDDB_Converter::verify( $qid );
					if ( ! $v['ok'] ) {
						$bad++;
					}
					?>
					<tr class="sddb-verify <?php echo $v['ok'] ? 'sddb-ok' : 'sddb-bad'; ?>">
						<td><?php echo (int) $qid; ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $qid ) ); ?>"><?php echo esc_html( get_the_title( $qid ) ); ?></a></td>
						<td>
							<?php if ( $v['ok'] ) : ?>
								<span style="color:#008a20">✓</span> <?php echo esc_html( implode( ' · ', $v['answers'] ) ); ?>
							<?php else : ?>
								<span style="color:#b32d2e"><?php echo esc_html( implode( ' ', $v['problems'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) get_post_meta( $qid, SDDB_Converter::META_FLAG, true ) ) ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( self::action_url( 'revert', $qid ) ); ?>">Revert</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<script>document.getElementById('sddb-verify-summary').innerHTML = <?php echo wp_json_encode( '<strong>' . ( count( $converted ) - $bad ) . '</strong> passed check · <strong>' . $bad . '</strong> with problems' ); ?>;</script>
			<?php endif; ?>
		</div>
		<?php
	}
}
