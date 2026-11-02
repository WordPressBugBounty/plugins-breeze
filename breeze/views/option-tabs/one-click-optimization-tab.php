<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
?>

<section class="breeze-one-click-optimization-section">
	<div class="br-section-title">
		<img src="<?php echo esc_url( BREEZE_PLUGIN_URL . 'assets/images/one-click-optimization-active.png' ); ?>"/>
		<?php esc_html_e( 'ONE-CLICK OPTIMIZATION', 'breeze' ); ?>
	</div>

	<?php
	if ( isset( $_GET['breeze_optimization_applied'] ) && $_GET['breeze_optimization_applied'] === '1' ) {
		?>
		<div class="breeze-top-notice">
			<p><?php esc_html_e( 'Optimization Applied Successfully!', 'breeze' ); ?></p>
		</div>
		<?php
	}
	?>

	<p><?php esc_html_e( 'Choose the setting that suits your needs. Breeze will apply the recommended improvements for a better website experience.', 'breeze' ); ?></p>

	<div class="compatibility-notice optimization-notice" id="breeze-compatibility-notice" style="display: none;">
		<h3><?php esc_html_e( 'Compatibility Issues Detected', 'breeze' ); ?></h3>
		<p><?php esc_html_e( 'We have found some compatibility issues while enabling the Expert Optimization Settings. We recommend choosing a lower optimization level before proceeding.', 'breeze' ); ?></p>
		<div id="compatibility-issues-list"></div>
		<div class="compatibility-actions">
			<button class="button button-primary optimization-continue"><?php esc_html_e( 'Continue Anyway', 'breeze' ); ?></button>
			<button class="button optimization-cancel"><?php esc_html_e( 'Don\'t Proceed', 'breeze' ); ?></button>
		</div>
	</div>

	<div class="optimization-success optimization-notice" id="breeze-optimization-success" style="display: none;">
		<h3><?php esc_html_e( 'Optimization Applied Successfully!', 'breeze' ); ?></h3>
		<p><?php esc_html_e( 'The selected optimization level has been applied to your website.', 'breeze' ); ?></p>
	</div>

	<div class="optimization-success optimization-notice" id="breeze-optimization-restore" style="display: none;">
		<h3><?php esc_html_e( 'Settings Restored Successfully', 'breeze' ); ?></h3>
		<p><?php esc_html_e( 'All of the one click optimization has been disabled and previous settings has been restored successfully', 'breeze' ); ?></p>
	</div>

	<div class="optimization-levels">
		<div class="optimization-level basic <?php echo ( get_option( 'breeze_applied_optimization' ) === 'basic' ) ? 'applied' : ''; ?>">
			<div class="level-header">
				<h3><?php esc_html_e( 'Standard', 'breeze' ); ?><?php echo ( get_option( 'breeze_applied_optimization' ) === 'basic' ) ? '<span class="applied-tag">' . esc_html__( 'Enabled', 'breeze' ) . '</span>' : ''; ?></h3>
				<span class="level-description"><?php esc_html_e( 'Recommended for most websites.', 'breeze' ); ?></span>
			</div>
			<div class="level-features">
				<ul>
					<li><?php esc_html_e( 'Gzip Compression', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'Browser Cache', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'Lazy Load Images', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'HTML Minify', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'CSS Minify', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'Disable Emoji', 'breeze' ); ?></li>
				</ul>
			</div>
			<div class="level-action">
				<button class="button button-primary apply-optimization <?php echo ( get_option( 'breeze_applied_optimization' ) === 'basic' ) ? 'br-disabled' : ''; ?>"
						data-level="basic"><?php esc_html_e( 'Enable Standard Optimization ', 'breeze' ); ?></button>
			</div>
		</div>

		<div class="optimization-level advanced <?php echo ( get_option( 'breeze_applied_optimization' ) === 'advanced' ) ? 'applied' : ''; ?>">
			<div class="level-header">
				<h3><?php esc_html_e( 'Advanced', 'breeze' ); ?><?php echo ( get_option( 'breeze_applied_optimization' ) === 'advanced' ) ? '<span class="applied-tag">' . esc_html__( 'Enabled', 'breeze' ) . '</span>' : ''; ?></h3>
				<span class="level-description"><?php esc_html_e( 'Recommended for modern themes and plugins.', 'breeze' ); ?></span>
			</div>
			<div class="level-features">
				<ul>
					<li><?php esc_html_e( 'All from Standard', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'Lazy Load for iframes and videos', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'Include Inline CSS', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'Combine CSS', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'Preload Links', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'Host Files Locally: only Google Fonts', 'breeze' ); ?></li>
				</ul>
			</div>
			<div class="level-action">
				<button class="button button-primary apply-optimization <?php echo ( get_option( 'breeze_applied_optimization' ) === 'advanced' ) ? 'br-disabled' : ''; ?>"
						data-level="advanced"><?php esc_html_e( 'Enable Advanced Optimization ', 'breeze' ); ?></button>
			</div>
		</div>

		<div class="optimization-level expert <?php echo ( get_option( 'breeze_applied_optimization' ) === 'expert' ) ? 'applied' : ''; ?>">
			<div class="level-header">
				<h3><?php esc_html_e( 'Expert', 'breeze' ); ?><?php echo ( get_option( 'breeze_applied_optimization' ) === 'expert' ) ? '<span class="applied-tag">' . esc_html__( 'Enabled', 'breeze' ) . '</span>' : ''; ?></h3>
				<span class="level-description"><?php esc_html_e( 'Recommended for simpler sites and testing.', 'breeze' ); ?></span>
			</div>
			<div class="level-features">
				<ul>
					<li><?php esc_html_e( 'All from Advanced', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'JS Minify and Include Inline JS', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'Delay All JavaScript', 'breeze' ); ?></li>
					<li><?php esc_html_e( 'Host Files Locally: Google Fonts and Gravatars', 'breeze' ); ?></li>
				</ul>
			</div>
			<div class="level-action">
				<button class="button button-primary apply-optimization <?php echo ( get_option( 'breeze_applied_optimization' ) === 'expert' ) ? 'br-disabled' : ''; ?>"
						data-level="expert"><?php esc_html_e( 'Enable Expert Optimization', 'breeze' ); ?></button>
			</div>
		</div>
	</div>

	<?php
	$restore_style = '';
	if ( ! get_option( 'breeze_applied_optimization' ) ) {
		$restore_style = 'display:none';
	}
	?>
	<div style="<?php echo esc_attr( $restore_style ); ?>" class="restore-settings">
		<p><?php esc_html_e( 'If you experience any issues after applying an optimization level, you can restore your previous settings.', 'breeze' ); ?></p>
		<button class="button"
				id="restore-settings-button"><?php esc_html_e( 'Restore Previous Settings', 'breeze' ); ?></button>
	</div>
</section>
