<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks administrators to review Breeze on WordPress.org.
 */
class Breeze_Review_Notice {
	const REVIEW_URL = 'https://wordpress.org/support/plugin/breeze/reviews/?filter=5#new-post';
	const ACTION     = 'breeze_review_notice';
	const NONCE      = 'breeze_review_nonce';
	const USER_META  = 'breeze_review_notice_dismissed';
	const LATER_META = 'breeze_review_notice_later';

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'display_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'display_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_choice' ) );
	}

	public function display_notice() {
		if (
			! $this->user_can_review()
			|| get_user_meta( get_current_user_id(), self::USER_META, true )
			|| (int) get_user_meta( get_current_user_id(), self::LATER_META, true ) > time()
		) {
			return;
		}
		?>
		<div class="notice notice-info" style="border-left-color: #13acdf;margin-left: 0;">
			<div style="display:flex;align-items: center;gap: 10px;">
				<div>
					<p style="margin:0 0 4px;"><strong><?php esc_html_e( 'Enjoying Breeze?', 'breeze' ); ?></strong></p>
					<p style="margin:0 0 10px;"><?php esc_html_e( 'If Breeze has made your website faster, we would appreciate a review on WordPress.org. Your feedback helps us continue improving Breeze for everyone.', 'breeze' ); ?></p>
				</div>
				<?php // The action stays in the URL because breeze.php loads this class only when $_GET['action'] matches. ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=' . self::ACTION ) ); ?>" rel="noopener noreferrer" style="margin:0;display: flex;align-items: center;gap: 5px;">
					<?php wp_nonce_field( self::ACTION, self::NONCE ); ?>
					<button type="submit" name="choice" value="done" class="button button-primary" formtarget="_blank" style="background-color: #2271b1;"><?php esc_html_e( 'Rate Breeze', 'breeze' ); ?></button>
					<button type="submit" name="choice" value="later" class="button" style="color:#2271b1;border-color:#2271b1;"><?php esc_html_e( 'Maybe later', 'breeze' ); ?></button>
					<button type="submit" name="choice" value="dismiss" class="button-link" style="margin-left:8px;color:#2271b1;"><?php esc_html_e( 'Don’t ask again', 'breeze' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	public function handle_choice() {
		$nonce    = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';
		$is_valid = wp_verify_nonce( $nonce, self::ACTION ) && $this->user_can_review();
		$choice   = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';

		if ( $is_valid && ( 'done' === $choice || 'dismiss' === $choice ) ) {
			update_user_meta( get_current_user_id(), self::USER_META, 1 );
		} elseif ( $is_valid && 'later' === $choice ) {
			update_user_meta( get_current_user_id(), self::LATER_META, time() + 30 * DAY_IN_SECONDS );
		}

		if ( 'done' === $choice ) {
			wp_redirect( self::REVIEW_URL ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	private function user_can_review() {
		return is_multisite() ? breeze_user_can_manage_network() : current_user_can( 'manage_options' );
	}
}

add_action(
	'admin_init',
	function () {
		new Breeze_Review_Notice();
	}
);
