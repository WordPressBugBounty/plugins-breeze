<?php
/**
 * Basic tab
 */
if ( ! defined( 'ABSPATH' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}

set_as_network_screen();

$is_custom = false;
if ( ( ! defined( 'WP_NETWORK_ADMIN' ) || ( defined( 'WP_NETWORK_ADMIN' ) && false === WP_NETWORK_ADMIN ) ) && is_multisite() ) {
	$get_inherit = get_option( 'breeze_inherit_settings', '1' );
	$is_custom   = filter_var( $get_inherit, FILTER_VALIDATE_BOOLEAN );
}

$options                      = breeze_get_option( 'file_settings', true );
$excluded_css_check           = true;
$excluded_js_check            = true;
$excluded_css_check_extension = true;
$excluded_js_check_extension  = true;
$excluded_url_list            = true;

$breeze_wp_ghost_active = defined( 'HMWP_VERSION' ) || defined( 'HMWP_PATH' ) || class_exists( 'HMWP', false );

$icon = BREEZE_PLUGIN_URL . 'assets/images/file-active.png';
?>
<form data-section="file">
	<?php if ( true === $is_custom ) { ?>
        <div class="br-overlay-disable"><?php esc_html_e( 'Settings are inherited', 'breeze' ); ?></div>
	<?php } ?>

    <?php
    Breeze_One_Click_Optimization::one_click_optimization_notice();
    ?>

	<section>
        <div class="br-section-title">
            <img src="<?php echo esc_url( $icon ); ?>"/>
			<?php esc_html_e( 'FILE OPTIMIZATION', 'breeze' ); ?>
        </div>

        <div class="br-option-group br-top">
            <span class="section-title"><?php esc_html_e( 'HTML Settings', 'breeze' ); ?></span>
            <!-- START OPTION -->
            <div class="br-beta br-option-item br-top">
                <div class="br-label">
                    <div class="br-option-text">
                        <?php esc_html_e( 'Cache Full Page HTML', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
                    <?php
                    $html_cache_setting = array_key_exists( 'breeze-enable-html-cache', $options ) ? $options['breeze-enable-html-cache'] : '1';
                    $basic_value        = filter_var( $html_cache_setting, FILTER_VALIDATE_BOOLEAN );
                    $is_enabled         = ( true === $basic_value ) ? checked( $html_cache_setting, '1', false ) : '';
                    ?>
                    <div class="on-off-checkbox">
                        <label class="br-switcher">
                            <input id="enable-html-cache" name="enable-html-cache" type="checkbox" class="br-box"
                                   value="1" <?php echo esc_attr($is_enabled); ?>>
                            <div class="br-see-state">
                            </div>
                        </label><br>
                    </div>

                    <div class="br-note">
                        <p>
                            <?php
                            esc_html_e( 'Saves a copy of the page so it can load faster. This is not the same as Cache System in the Basic tab.', 'breeze' );
                            ?>
                        </p>
                        <p>
                            <?php
                            esc_html_e( 'Recommend: keep this on for faster pages.', 'breeze' );
                            ?>
                        </p>

                        <p class="br-important">
                            <?php
                            echo '<strong>';
                            esc_html_e( 'Important: ', 'breeze' );
                            echo '</strong>';
                            esc_html_e( 'This option works at the plugin level. Varnish still runs on the server.', 'breeze' );
                            ?>
                        </p>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->

            <!-- START OPTION -->
            <?php
            $enable_html_cache = isset( $options['breeze-enable-html-cache'] ) ? filter_var( $options['breeze-enable-html-cache'], FILTER_VALIDATE_BOOLEAN ) : '1';

            $disable_overlay = '';
            if ( false === $enable_html_cache ) {
                $disable_overlay = ' br-apply-disable';
            }
            ?>
            <div class="br-beta br-option-item<?php echo $disable_overlay; ?>">
                <div class="br-label">
                    <div class="br-option-text">
                        <?php esc_html_e( 'Refresh Cached Page Parts', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
                    <?php
                    $basic_value = isset( $options['breeze-html-doublecheck'] ) ? filter_var( $options['breeze-html-doublecheck'], FILTER_VALIDATE_BOOLEAN ) : false;
                    $is_enabled  = ( isset( $basic_value ) && true === $basic_value ) ? checked( $options['breeze-html-doublecheck'], '1', false ) : '';
                    ?>
                    <div class="on-off-checkbox">
                        <label class="br-switcher">
                            <input id="html-doublecheck" name="html-doublecheck" type="checkbox" class="br-box"
                                   value="0" <?php echo esc_attr($is_enabled); ?>>
                            <div class="br-see-state">
                            </div>
                        </label><br>
                    </div>

                    <div class="br-note">
                        <p>
                            <?php
                            esc_html_e( 'After the cached page loads, Breeze refreshes only the page parts you list below so they stay up to date.', 'breeze' );
                            ?>
                        </p>

                        <p class="br-important">
                            <?php
                            echo '<strong>';
                            esc_html_e( 'Important: ', 'breeze' );
                            echo '</strong>';
                            esc_html_e( 'Use this only when a cached page shows old content in one block, such as a mini-cart.', 'breeze' );
                            ?>
                        </p>
                        <p class="br-important">
                            <?php
                            echo '<strong>';
                            esc_html_e( 'Caution: ', 'breeze' );
                            echo '</strong>';
                            esc_html_e( 'This option can affect page response. Leave it off if the site already looks correct.', 'breeze' );
                            ?>
                        </p>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->

            <div class="doublecheck-options">
                <!-- START OPTION -->
                <?php


                $dc_enabled       = isset( $options['breeze-html-doublecheck'] ) ? filter_var( $options['breeze-html-doublecheck'], FILTER_VALIDATE_BOOLEAN ) : false;
                $is_front_display = '';
                if ( false === $dc_enabled ) {
                    $is_front_display = ' style="display: none"';
                }


                $doublecheck_elements = '';
                if ( ! empty( $options['breeze-doublecheck-elements'] ) ) {
                    $output               = implode( "\n", $options['breeze-doublecheck-elements'] );
                    $doublecheck_elements = esc_textarea( $output );
                }

                ?>
                <div class="br-beta br-option-item" <?php echo $is_front_display; ?>>
                    <div class="br-label">
                        <div class="br-option-text">
                            <?php esc_html_e( 'Page Parts To Refresh', 'breeze' ); ?>
                        </div>
                    </div>
                    <div class="br-option">
					<textarea cols="100" rows="7" id="doublecheck-elements" name="doublecheck-elements"
                              placeholder="Example:&#10;#site-header-cart"><?php echo esc_attr($doublecheck_elements); ?></textarea>
                        <div class="br-note">
                            <p>
                                <?php
                                echo wp_kses(
                                    sprintf(
                                        /* translators: 1: "# for an ID" in bold, 2: ". for a class" in bold */
                                        __( 'Enter one page part per line, for example #site-header-cart. Use %1$s and %2$s.', 'breeze' ),
                                        '<strong># for an ID</strong>',
                                        '<strong>. for a class</strong>'
                                    ),
                                    array(
                                        'strong' => array(),
                                    )
                                );
                                ?>
                            </p>
							<p class="br-important">
								<?php
								echo '<strong>';
								esc_html_e( 'Important: ', 'breeze' );
								echo '</strong>';
								esc_html_e( 'Do not enter html, body, or head. You can still target something inside the page, such as body .mini-cart. Do not target login, checkout, or add-to-cart forms.', 'breeze' );
								?>
							</p>
							<?php if ( ! empty( $options['breeze-doublecheck-elements-error'] ) ) { ?>
								<p class="br-notice">
									<?php echo esc_html( $options['breeze-doublecheck-elements-error'] ); ?>
								</p>
							<?php } ?>
                        </div>
                    </div>
                </div>
                <!-- END OPTION -->

                <!-- START OPTION -->
                <?php
                $doublecheck_load_options = array(
                        'preload' => __( 'Before Load', 'breeze' ),
                        'onload'  => __( 'After loaded', 'breeze' ),
                        'async'   => __( 'Async', 'breeze' ),
                );

                ?>
                <div class="br-beta br-option-item loading-type-option" style="display: none !important">
                    <div class="br-label">
                        <div class="br-option-text">
                            <?php esc_html_e( 'Double-check loading type', 'breeze' ); ?>
                        </div>
                    </div>
                    <div class="br-option">
                        <?php
                        $select_active = isset( $options['breeze-doublecheck-load'] ) ? $options['breeze-doublecheck-load'] : '';
                    
                        if($select_active !== 'async') {
                            $select_active = 'async';
                        }
                        ?>
                        <select name="doublecheck-load" id="doublecheck-load">
                            <?php
                            foreach ( $doublecheck_load_options as $value => $label ) {
                                $selected = '';
                                if ( $select_active === (string) $value || empty( $select_active ) ) {
                                    $selected = 'selected';
                                }
                                $value = esc_attr($value);
                                $label = esc_html($label);
                                echo "<option value='{$value}' {$selected}>{$label}</option>";
                            }
                            ?>
                        </select>

                    </div>
                </div>
                <!-- END OPTION -->

                <!-- START OPTION -->
                <?php
                $dc_enabled       = isset( $options['breeze-html-doublecheck'] ) ? filter_var( $options['breeze-html-doublecheck'], FILTER_VALIDATE_BOOLEAN ) : false;
                $is_front_display = '';
                if ( false === $dc_enabled ) {
                    $is_front_display = ' style="display: none"';
                }
                ?>

                <div class="br-beta br-option-item" <?php echo $is_front_display; ?>>
                    <div class="br-label">
                        <div class="br-option-text">
                            <?php esc_html_e( 'Show Loading Overlay', 'breeze' ); ?>
                        </div>
                    </div>
                    <div class="br-option">
                        <?php
                        $basic_value = isset( $options['breeze-html-doublecheck-loader'] ) ? filter_var( $options['breeze-html-doublecheck-loader'], FILTER_VALIDATE_BOOLEAN ) : false;
                        $is_enabled  = ( isset( $basic_value ) && true === $basic_value ) ? checked( $options['breeze-html-doublecheck-loader'], '1', false ) : '';
                        ?>
                        <div class="on-off-checkbox">
                            <label class="br-switcher">
                                <input id="html-doublecheck-loader" name="html-doublecheck-loader" type="checkbox"
                                       class="br-box"
                                       value="1" <?php echo esc_attr($is_enabled); ?>>
                                <div class="br-see-state">
                                </div>
                            </label><br>
                        </div>

                        <div class="br-note">
                            <p>
                                <?php
                                esc_html_e( 'Shows a short overlay while those page parts update. This depends on your theme and may not look perfect on every site.', 'breeze' );
                                ?>
                            </p>
                            <p class="br-important">
                                <?php
                                echo '<strong>';
                                esc_html_e( 'Note: ', 'breeze' );
                                echo '</strong>';
                                esc_html_e( 'Theme developers can style the overlay using the class breeze-dc-elem.', 'breeze' );
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
                <!-- END OPTION -->
            </div>

            <!-- START OPTION -->
            <?php
            $dc_enabled       = isset( $options['breeze-html-doublecheck-loader'] ) ? filter_var( $options['breeze-html-doublecheck-loader'], FILTER_VALIDATE_BOOLEAN ) : false;
            $is_front_display = '';
            if ( false === $dc_enabled ) {
                $is_front_display = ' style="display: none"';
            }
            ?>

            <div class="br-beta br-option-item" <?php echo $is_front_display; ?>>
                <div class="br-label">
                    <div class="br-option-text">
                        <?php esc_html_e( 'Overlay Color', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
                    <?php
                    $basic_value = ( ! empty( $options['breeze-html-doublecheck-loader-overlay'] ) ? esc_attr( $options['breeze-html-doublecheck-loader-overlay'] ) : '#000000' );
                    ?>
                    <div class="on-off-checkbox br-doublecheck-color-control">
                        <input id="html-doublecheck-loader-overlay-picker" type="color"
                               pattern="^#[A-Fa-f0-9]{6}$" class="br-doublecheck-color-picker" value="<?php echo esc_attr( $basic_value ); ?>">
                        <input id="html-doublecheck-loader-overlay" name="html-doublecheck-loader-overlay" type="text"
                               placeholder="#000000" pattern="^#[A-Fa-f0-9]{6}$" class="br-box br-doublecheck-color-code" value="<?php echo esc_attr( $basic_value ); ?>">
                    </div>

                    <div class="br-note">
                        <p>
                            <?php
                            esc_html_e( 'Set the background color for the loading overlay.', 'breeze' );
                            ?>
                        </p>

                    </div>
                </div>
            </div>
            <!-- END OPTION -->

            <!-- START OPTION -->
            <?php
            // Exclusions apply whenever Double-check runs, so this row follows
            // Refresh Cached Page Parts. The loader toggle only reveals Overlay Color.
            $dc_enabled       = isset( $options['breeze-html-doublecheck'] ) ? filter_var( $options['breeze-html-doublecheck'], FILTER_VALIDATE_BOOLEAN ) : false;
            $is_front_display = '';
            if ( false === $dc_enabled ) {
                $is_front_display = ' style="display: none"';
            }


            $doublecheck_elements = '';
            if ( ! empty( $options['breeze-doublecheck-exclude-url'] ) ) {
                $output               = implode( "\n", $options['breeze-doublecheck-exclude-url'] );
                $doublecheck_elements = esc_textarea( $output );
            }

            ?>
            <div class="br-beta br-option-item" <?php echo $is_front_display; ?>>
                <div class="br-label">
                    <div class="br-option-text">
                        <?php esc_html_e( 'Do Not Refresh On These Pages', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
					<textarea cols="100" rows="7" id="doublecheck-exclude-url" name="doublecheck-exclude-url"
                              placeholder="Example:&#10;yourwebsite.com/posttype/post"><?php echo $doublecheck_elements; ?></textarea>
                    <div class="br-note">
                        <p>
                            <?php
                            esc_html_e( 'Add one full page URL or path per line, for example /cart/. Exact page only. The page can still be cached.', 'breeze' );
                            ?>
                        </p>
                        <p class="br-important">
                            <?php
                            echo '<strong>';
                            esc_html_e( 'Important: ', 'breeze' );
                            echo '</strong>';
                            esc_html_e( 'Wildcards such as /shop/* are not allowed. Use Never Cache URLs if the whole page must stay uncached.', 'breeze' );
                            ?>
                        </p>
						<?php if ( ! empty( $options['breeze-doublecheck-exclude-url-error'] ) ) { ?>
							<p class="br-notice">
								<?php echo esc_html( $options['breeze-doublecheck-exclude-url-error'] ); ?>
							</p>
						<?php } ?>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->

            <!-- START OPTION -->
            <div class="br-option-item">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'HTML Minify', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
					<?php
					$basic_value = isset( $options['breeze-minify-html'] ) ? filter_var( $options['breeze-minify-html'], FILTER_VALIDATE_BOOLEAN ) : false;
					$is_enabled  = ( isset( $basic_value ) && true === $basic_value ) ? checked( $options['breeze-minify-html'], '1', false ) : '';
					?>
                    <div class="on-off-checkbox">
                        <label class="br-switcher">
                            <input id="minification-html" name="minification-html" type="checkbox" class="br-box" value="1" <?php echo esc_attr( $is_enabled ); ?>>
                            <div class="br-see-state">
                            </div>
                        </label><br>
                    </div>

                    <div class="br-note">
                        <p>
							<?php

							esc_html_e( 'Minifying HTML removes whitespace and comments to reduce the size.', 'breeze' );
							?>
                        </p>

                        <p class="br-important">
							<?php
							echo '<strong>';
							esc_html_e( 'Important: ', 'breeze' );
							echo '</strong>';
							esc_html_e( 'We recommend testing minification on a staging website before deploying it on a live website. ', 'breeze' );
							echo '<br/>';
							esc_html_e( 'Minification is known to cause issues on the frontend.', 'breeze' );
							?>
                        </p>
						<?php if ( $breeze_wp_ghost_active ) { ?>
							<p class="br-notice">
								<?php esc_html_e( 'WP Ghost is active. Disable HTML Minify to avoid layout issues, as it is not compatible with WP Ghost.', 'breeze' ); ?>
							</p>
						<?php } ?>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->
        </div><!-- END GROUP -->

        <!-- START GROUP -->
        <div class="br-option-group">
            <span class="section-title"><?php esc_html_e( 'CSS Settings', 'breeze' ); ?></span>
            <!-- START OPTION -->
            <div class="br-option-item br-top">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'CSS Minify', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
					<?php
					$basic_value = isset( $options['breeze-minify-css'] ) ? filter_var( $options['breeze-minify-css'], FILTER_VALIDATE_BOOLEAN ) : false;
					$is_enabled  = ( isset( $basic_value ) && true === $basic_value ) ? checked( $options['breeze-minify-css'], '1', false ) : '';

					$css_minify_state = true;
					if ( empty( $is_enabled ) ) {
						$css_minify_state = false;
					}

					?>
                    <div class="on-off-checkbox">
                        <label class="br-switcher">
                            <input id="minification-css" name="minification-css" type="checkbox" class="br-box" value="1" <?php echo esc_attr( $is_enabled ); ?>>
                            <div class="br-see-state">
                            </div>
                        </label><br>
                    </div>

                    <div class="br-note">
                        <p>
							<?php esc_html_e( 'Minify CSS removes whitespace and comments to reduce the file size.', 'breeze' ); ?>
                        </p>
                    </div>

					<?php
					$is_font_display = '';
					if ( empty( $is_enabled ) ) {
						$is_font_display = ' style="display: none"';
					}

					$basic_value = isset( $options['breeze-font-display-swap'] ) ? filter_var( $options['breeze-font-display-swap'], FILTER_VALIDATE_BOOLEAN ) : false;
					$is_enabled  = ( isset( $basic_value ) && true === $basic_value ) ? checked( $options['breeze-font-display-swap'], '1', false ) : '';
					?>
                    <div id="font-display-swap" <?php echo esc_attr( $is_font_display ); ?>>
                        <div class="on-off-checkbox">
                            <label class="br-switcher">
                                <input id="font-display" name="font-display" type="checkbox" class="br-box" value="1" <?php echo esc_attr( $is_enabled ); ?>>
                                <div class="br-see-state">
                                </div>
                            </label><br>
                        </div>

                        <p>
							<?php esc_html_e( 'Font remain visible during load', 'breeze' ); ?><br/>
                        </p>
                    </div>

                    <p class="br-important">
						<?php
						echo '<strong>';
						esc_html_e( 'Important: ', 'breeze' );
						echo '</strong>';
						esc_html_e( 'We recommend testing minification on a staging website before deploying it on a live website. ', 'breeze' );
						echo '<br/>';
						esc_html_e( 'Minification is known to cause issues on the frontend.', 'breeze' );
						?>
                    </p>
                </div>
            </div>
            <!-- END OPTION -->

            <!-- START OPTION -->
			<?php
			$basic_value        = isset( $options['breeze-include-inline-css'] ) ? filter_var( $options['breeze-include-inline-css'], FILTER_VALIDATE_BOOLEAN ) : false;
			$is_enabled         = ( isset( $basic_value ) && true === $basic_value ) ? checked( $options['breeze-include-inline-css'], '1', false ) : '';
			$disable_inline_css = '';
			$disable_overlay    = '';

			if ( false === $css_minify_state ) {
				//$disable_inline_css = 'disabled="disabled"';
				$disable_overlay = ' br-apply-disable';
			}
			?>
            <div class="br-option-item<?php echo esc_attr( $disable_overlay ); ?>">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'Include Inline CSS', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">

                    <div class="on-off-checkbox">
                        <label class="br-switcher">
                            <input id="include-inline-css" name="include-inline-css" type="checkbox" class="br-box" value="1" <?php echo esc_attr( $is_enabled ); ?> <?php echo esc_attr( $disable_inline_css ); ?>>
                            <div class="br-see-state">
                            </div>
                        </label><br>
                    </div>

                    <div class="br-note">
                        <p>
							<?php esc_html_e( 'Minify Inline CSS removes whitespace and create seprate cache file for inline CSS.', 'breeze' ); ?>
                        </p>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->

            <!-- START OPTION -->
			<?php
			$basic_value = isset( $options['breeze-group-css'] ) ? filter_var( $options['breeze-group-css'], FILTER_VALIDATE_BOOLEAN ) : false;
			$is_enabled  = ( isset( $basic_value ) && true === $basic_value ) ? checked( $options['breeze-group-css'], '1', false ) : '';

			$disable_group_css = '';
			$disable_overlay   = '';

			if ( false === $css_minify_state ) {
				//$disable_group_css = 'disabled="disabled"';
				$disable_overlay = ' br-apply-disable';
			}
			?>
            <div class="br-option-item<?php echo esc_attr( $disable_overlay ); ?>">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'Combine CSS', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">

                    <div class="on-off-checkbox">
                        <label class="br-switcher">
                            <input id="group-css" name="group-css" type="checkbox" class="br-box" value="1" <?php echo esc_attr( $is_enabled ); ?> <?php echo esc_attr( $disable_group_css ); ?>>
                            <div class="br-see-state">
                            </div>
                        </label><br>
                    </div>

                    <div class="br-note">
                        <p>
							<?php esc_html_e( 'Combine CSS merges all your minified files into a single file, reducing HTTP requests.', 'breeze' ); ?>
                        </p>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->

            <!-- START OPTION -->
			<?php

			if ( isset( $options['breeze-exclude-css'] ) && ! empty( $options['breeze-exclude-css'] ) ) {
				$excluded_css_check = breeze_validate_urls( $options['breeze-exclude-css'] );
				if ( true === $excluded_css_check ) {
					$excluded_css_check_extension = breeze_validate_the_right_extension( $options['breeze-exclude-css'], 'css' );
				}
			}

			$css_output = '';
			if ( ! empty( $options['breeze-exclude-css'] ) ) {
				$output     = implode( "\n", $options['breeze-exclude-css'] );
				$css_output = esc_textarea( $output );
			}
			$disable_overlay = '';
			if ( false === $css_minify_state ) {
				$disable_overlay = ' br-apply-disable';
			}
			?>
            <div class="br-option-item<?php echo esc_attr( $disable_overlay ); ?>">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'Exclude CSS', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">

					<textarea cols="100" rows="7" id="exclude-css" name="exclude-css"
                              placeholder="Exclude CSS on the basis of the folder&#10;https://demo/wp-content/plugins/some-plugin/assets/css/demo(.*)&#10;&#10;Exclude CSS on the basis of the file name&#10;https://demo/wp-content/plugins/some-plugin/assets/css/demo_1/css_random_someplugin_(.*).css"><?php echo esc_textarea( $css_output ); ?></textarea>
                    <div class="br-note">
                        <p>
							<?php

							esc_html_e( 'Use this option to exclude CSS files from Minification and Grouping. Enter the URLs of CSS files on each line.', 'breeze' );
							?>
                        </p>
                        <p class="br-notice">
							<?php if ( false === $excluded_css_check_extension ) { ?>
								<?php esc_html_e( 'One (or more) URL is incorrect. Please confirm that all URLs have the .css extension', 'breeze' ); ?>
							<?php } ?>
							<?php if ( false === $excluded_css_check ) { ?>
								<?php esc_html_e( 'One (or more) URL is invalid. Please check and correct the entry.', 'breeze' ); ?>
							<?php } ?>
                        </p>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->
        </div><!-- END GROUP -->

        <!-- START GROUP -->
        <div class="br-option-group">
            <span class="section-title"><?php esc_html_e( 'JS Settings', 'breeze' ); ?></span>
            <!-- START OPTION -->
            <div class="br-option-item br-top">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'JS Minify', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
					<?php
					$basic_value = isset( $options['breeze-minify-js'] ) ? filter_var( $options['breeze-minify-js'], FILTER_VALIDATE_BOOLEAN ) : false;
					$is_enabled  = ( isset( $basic_value ) && true === $basic_value ) ? checked( $options['breeze-minify-js'], '1', false ) : '';

					$js_minify_state = true;
					if ( empty( $is_enabled ) ) {
						$js_minify_state = false;
					}
					?>
                    <div class="on-off-checkbox">
                        <label class="br-switcher">
                            <input id="minification-js" name="minification-js" type="checkbox" class="br-box" value="1" <?php echo esc_attr( $is_enabled ); ?>>
                            <div class="br-see-state">
                            </div>
                        </label><br>
                    </div>

                    <div class="br-note">
                        <p>
							<?php esc_html_e( 'Minify JavaScript removes whitespace and comments to reduce the file size.', 'breeze' ); ?>
                        </p>

                        <p class="br-important">
							<?php
							echo '<strong>';
							esc_html_e( 'Important: ', 'breeze' );
							echo '</strong>';
							esc_html_e( 'We recommend testing minification on a staging website before deploying it on a live website. ', 'breeze' );
							echo '<br/>';
							esc_html_e( 'Minification is known to cause issues on the frontend.', 'breeze' );
							?>
                        </p>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->

            <!-- START OPTION -->
			<?php
			$basic_value = isset( $options['breeze-include-inline-js'] ) ? filter_var( $options['breeze-include-inline-js'], FILTER_VALIDATE_BOOLEAN ) : false;
			$is_enabled  = ( isset( $basic_value ) && true === $basic_value ) ? checked( $options['breeze-include-inline-js'], '1', false ) : '';

			$js_inline_minify = true;
			if ( empty( $is_enabled ) ) {
				$js_inline_minify = false;
			}

			$disable_inline_js = '';
			$disable_overlay   = '';
			if ( false === $js_minify_state ) {
				//$disable_inline_js = 'disabled="disabled"';
				$disable_overlay = ' br-apply-disable';
			}
			?>
            <div class="br-option-item<?php echo esc_attr( $disable_overlay ); ?>">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'Include Inline JS', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">

                    <div class="on-off-checkbox">
                        <label class="br-switcher">
                            <input id="include-inline-js" name="include-inline-js" type="checkbox" class="br-box" value="1" <?php echo esc_attr( $is_enabled ); ?> <?php echo esc_attr( $disable_inline_js ); ?>>
                            <div class="br-see-state">
                            </div>
                        </label><br>
                    </div>

                    <div class="br-note">
                        <p>
							<?php esc_html_e( 'Minify Inline JS removes whitespace and create seprate cache file for inline JS.', 'breeze' ); ?>

                        </p>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->

            <!-- START OPTION -->
			<?php
			$combine_js_is = isset( $options['breeze-group-js'] ) ? filter_var( $options['breeze-group-js'], FILTER_VALIDATE_BOOLEAN ) : false;
			$is_enabled    = ( isset( $combine_js_is ) && true === $combine_js_is ) ? checked( $options['breeze-group-js'], '1', false ) : '';

			$disable_group_js = '';
			$disable_overlay  = '';

			if ( false === $js_minify_state ) {
				//$disable_group_js = 'disabled="disabled"';
				$disable_overlay = ' br-apply-disable';
			}
			?>
            <div class="br-option-item<?php echo esc_attr( $disable_overlay ); ?>">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'Combine JS', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
                    <div class="on-off-checkbox">
                        <label class="br-switcher">
                            <input id="group-js" name="group-js" type="checkbox" class="br-box" value="1" <?php echo esc_attr( $is_enabled ); ?> <?php echo esc_attr( $disable_group_js ); ?>>
                            <div class="br-see-state">
                            </div>
                        </label><br>
                    </div>

                    <div class="br-note">
                        <p>
							<?php esc_html_e( 'Combine JS merges all your minified files into a single file, reducing HTTP requests.', 'breeze' ); ?>
                        </p>
                        <p class="br-important">

							<?php
							echo '<strong>';
							esc_html_e( 'Important: ', 'breeze' );
							echo '</strong>';
							esc_html_e( 'This option can\'t be combined with "Delay JS Inline Script" or "Delay All JavaScript" .', 'breeze' );
							?>
                        </p>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->


            <!-- START OPTION -->
			<?php
			if ( isset( $options['breeze-exclude-js'] ) && ! empty( $options['breeze-exclude-js'] ) ) {
				$excluded_js_check = breeze_validate_urls( $options['breeze-exclude-js'] );
				if ( true === $excluded_js_check ) {
					$excluded_js_check_extension = breeze_validate_the_right_extension( $options['breeze-exclude-js'], 'js' );
				}
			}

			$js_output = '';
			if ( ! empty( $options['breeze-exclude-js'] ) ) {
				$output    = implode( "\n", $options['breeze-exclude-js'] );
				$js_output = esc_textarea( $output );
			}

			$disable_overlay = '';
			if ( false === $js_minify_state ) {
				$disable_overlay = ' br-apply-disable';
			}

			?>
            <div class="br-option-item<?php echo esc_attr( $disable_overlay ); ?>">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'Exclude JS', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
					<textarea cols="100" rows="7" id="exclude-js" name="exclude-js"
                              placeholder="Exclude JS on the basis of the folder&#10;https://demo/wp-content/plugins/some-plugin/assets/js/demo(.*)&#10;&#10;Exclude JS on the basis of the file name&#10;https://demo/wp-content/plugins/some-plugin/assets/js/demo_1/js_random_someplugin_(.*).js"><?php echo esc_textarea( $js_output ); ?></textarea>
                    <div class="br-note">
                        <p>
							<?php

							esc_html_e( 'Use this option to exclude JS files from Minification and Grouping. Enter the URLs of JS files on each line.', 'breeze' );
							?>
                        </p>
                        <p class="br-notice">
							<?php if ( false === $excluded_js_check_extension ) { ?>
								<?php esc_html_e( 'One (or more) URL is incorrect. Please confirm that all URLs have the .js extension', 'breeze' ); ?>
							<?php } ?>
							<?php if ( false === $excluded_js_check ) { ?>
								<?php esc_html_e( 'One (or more) URL is invalid. Please check and correct the entry.', 'breeze' ); ?>
							<?php } ?>
                        </p>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->
            <!-- START OPTION -->
            <div class="br-option-item">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'Delay JS Inline Scripts', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
					<?php
					// Delay All JavaScript START
					/**
					 * Used to disable one of the options when the ther is active.
					 */
					$basic_value_all = isset( $options['breeze-delay-all-js'] ) ? filter_var( $options['breeze-delay-all-js'], FILTER_VALIDATE_BOOLEAN ) : false;
					$is_enabled_all  = ( isset( $basic_value_all ) && true === $basic_value_all ) ? checked( $options['breeze-delay-all-js'], '1', false ) : '';
					// END

					$basic_value_inlinejs = isset( $options['breeze-enable-js-delay'] ) ? filter_var( $options['breeze-enable-js-delay'], FILTER_VALIDATE_BOOLEAN ) : false;
					$is_enabled_inlinejs  = ( isset( $basic_value_inlinejs ) && true === $basic_value_inlinejs ) ? checked( $options['breeze-enable-js-delay'], '1', false ) : '';

					$delay_inline_disabled = '';
					if ( true === $basic_value_all ) {
						$delay_inline_disabled = 'disabled="disabled"';
					}

                    $is_data_stop = '';
					if ( true === $combine_js_is && true === $basic_value_inlinejs ) {
						$delay_inline_disabled = '';
						$is_data_stop = 'data-noaction="1"';
					}
					?>
                    <div class="on-off-checkbox">
                        <label class="br-switcher">
                            <input <?php echo esc_attr( $delay_inline_disabled ); ?> id="enable-js-delay" name="enable-js-delay" type="checkbox" class="br-box" value="1" <?php echo esc_attr( $is_enabled_inlinejs ); ?> <?php echo esc_attr( $is_data_stop ); ?>>
                            <div class="br-see-state">
                            </div>
                        </label><br>
                    </div>
					<?php
					$js_output = '';
					if ( ! empty( $options['breeze-delay-js-scripts'] ) ) {
						$output    = implode( "\n", $options['breeze-delay-js-scripts'] );
						$js_output = esc_textarea( $output );
					}

					$display_text_area = 'style="display:none"';
					if ( true === $basic_value_inlinejs ) {
						$display_text_area = 'style="display:block"';
					}
					?>
                    <div <?php echo esc_attr( $display_text_area ); ?> id="breeze-delay-js-scripts-div">
                        <br/>
                        <textarea cols="100" rows="7" id="delay-js-scripts" name="delay-js-scripts"><?php echo esc_textarea( $js_output ); ?></textarea>
                        <div class="br-note">
                            <p>
								<?php

								esc_html_e( 'You can add specific keywords to identify the inline JavaScript to be delayed. Each script identifying keyword must be added on a new line.', 'breeze' );
								?>
                                <a href="https://www.cloudways.com/blog/breeze-1-2-version-released/" target="_blank"><?php esc_html_e( 'More info here', 'breeze' ); ?></a>
                            </p>
                            <p class="br-notice">
								<?php esc_html_e( 'Please clear Varnish after applying the new settings.', 'breeze' ); ?><br/>
								<?php esc_html_e( 'This option can\'t be combined with "Combine JS" .', 'breeze' ); ?>
                            </p>
                        </div>
                    </div>
                    <p class="br-important">
						<?php
						echo '<strong>';
						esc_html_e( 'Important: ', 'breeze' );
						echo '</strong>';
						esc_html_e( 'Use only one option "Delay JS Inline Scripts" OR "Delay All JavaScript" at same time.', 'breeze' );
						?>
                    </p>
                </div>
            </div>
            <!-- END OPTION -->

            <!-- START OPTION -->
            <div class="br-option-item">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'Delay All JavaScript', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
					<?php
					$basic_value_all = isset( $options['breeze-delay-all-js'] ) ? filter_var( $options['breeze-delay-all-js'], FILTER_VALIDATE_BOOLEAN ) : false;
					$is_enabled_all  = ( isset( $basic_value_all ) && true === $basic_value_all ) ? checked( $options['breeze-delay-all-js'], '1', false ) : '';

					$js_minify_state = true;
					if ( empty( $is_enabled_all ) ) {
						$js_minify_state = false;
					}

					$delay_js_disabled = '';
					if ( true === $basic_value_inlinejs ) {
						$delay_js_disabled = 'disabled="disabled"';
					}
					$is_data_stop = '';
					if ( true === $combine_js_is && true === $basic_value_all ) {
						$delay_inline_disabled = '';
						$is_data_stop = 'data-noaction="1"';
					}
					?>
                    <div class="on-off-checkbox">
                        <label class="br-switcher">
                            <input <?php echo esc_attr( $delay_js_disabled ); ?> id="breeze-delay-all-js" name="breeze-delay-all-js" type="checkbox" class="br-box" value="1" <?php echo esc_attr( $is_enabled_all ); echo esc_attr( $is_data_stop );?>>
                            <div class="br-see-state">
                            </div>
                        </label><br>
                    </div>

                    <div class="br-note">
                        <p>
							<?php esc_html_e( 'Improve the page load by delaying JavaScript execution.', 'breeze' ); ?>
                        </p>

                        <p class="br-important">
							<?php
							//                          echo '<strong>';
							//                          _e( 'Important: ', 'breeze' );
							//                          echo '</strong>';
							//                          _e( 'We recommend testing minification on a staging website before deploying it on a live website. ', 'breeze' );
							//                          echo '<br/>';
							//                          _e( 'Minification is known to cause issues on the frontend.', 'breeze' );
							?>
                        </p>
                    </div>

					<?php
					$js_output = '';
					if ( ! empty( $options['no-breeze-no-delay-js'] ) ) {
						$output    = implode( "\n", $options['no-breeze-no-delay-js'] );
						$js_output = esc_textarea( $output );
					}

					$display_text_area = 'style="display:none"';
					if ( true === $basic_value_all ) {
						$display_text_area = 'style="display:block"';
					}

					?>

                    <div <?php echo esc_attr( $display_text_area ); ?> id="breeze-delay-js-scripts-div-all">
                        <br/>
                        <div class="br-option-text"><strong><?php esc_html_e( 'List of scripts not to delay', 'breeze' ); ?></strong></div>
                        <textarea cols="100" rows="7" id="no-delay-js-scripts" name="no-delay-js-scripts"><?php echo esc_textarea( $js_output ); ?></textarea>
                        <div class="br-note">
                            <p>
								<?php

								esc_html_e( 'You can add specific keywords to identify the Inline JavaScript or JavaScript files to not be delayed. Each script identifying keyword must be added on a new line.', 'breeze' );
								?>
                            </p>
                            <p class="br-notice">
								<?php esc_html_e( 'Please clear Varnish after applying the new settings.', 'breeze' ); ?><br>
								<?php esc_html_e( 'This option can\'t be combined with "Combine JS" .', 'breeze' ); ?>

                            </p>
                        </div>
                    </div>
                    <p class="br-important">
						<?php
						echo '<strong>';
						esc_html_e( 'Important: ', 'breeze' );
						echo '</strong>';
						esc_html_e( 'Use only one option "Delay JS Inline Scripts" OR "Delay All JavaScript" at same time.', 'breeze' );
						?>
                    </p>
                </div>
            </div>
            <!-- END OPTION -->
            <!-- START OPTION -->
            <div class="br-option-item">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'Move JS Files to Footer', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
                    <div class="breeze-list-url">
						<?php if ( ! empty( $options['breeze-move-to-footer-js'] ) ) : ?>
							<?php foreach ( $options['breeze-move-to-footer-js'] as $js_url ) : ?>
                                <div class="breeze-input-group">
                                    <input type="text" size="98"
                                           class="breeze-input-url"
                                           name="move-to-footer-js[]"
                                           placeholder="<?php esc_attr_e( 'Enter URL...', 'breeze' ); ?>"
                                           value="<?php echo esc_html( $js_url ); ?>"/>
                                    <span class="sort-handle">
										<span class="dashicons dashicons-arrow-up moveUp"></span>
										<span class="dashicons dashicons-arrow-down moveDown"></span>
									</span>
                                    <span class="dashicons dashicons-no item-remove" title="<?php esc_attr_e( 'Remove', 'breeze' ); ?>"></span>
                                </div>
							<?php endforeach; ?>
						<?php else : ?>
                            <div class="breeze-input-group">
                                <input type="text" size="98"
                                       class="breeze-input-url"
                                       id="move-to-footer-js"
                                       name="move-to-footer-js[]"
                                       placeholder="<?php esc_attr_e( 'Enter URL...', 'breeze' ); ?>"
                                       value=""/>
                                <span class="sort-handle">
									<span class="dashicons dashicons-arrow-up moveUp"></span>
									<span class="dashicons dashicons-arrow-down moveDown"></span>
								</span>
                                <span class="dashicons dashicons-no item-remove" title="<?php esc_attr_e( 'Remove', 'breeze' ); ?>"></span>
                            </div>
						<?php endif; ?>
                    </div>
                    <div style="margin: 10px 0">
                        <button type="button" class="br-blue-button-reverse add-url" id="add-move-to-footer-js">
							<?php esc_html_e( 'Add URL', 'breeze' ); ?>
                        </button>
                    </div>
                    <div class="br-note">
                        <p>
							<?php

							esc_html_e( 'Enter the complete URLs of JS files to be moved to the footer during minification process.', 'breeze' );
							?>
                        </p>
                        <p class="br-important">
							<?php
							echo '<strong>';
							esc_html_e( 'Important: ', 'breeze' );
							echo '</strong>';
							esc_html_e( 'You should add the URL of original files as URL of minified files are not supported.', 'breeze' );
							?>
                        </p>

                    </div>
                </div>
            </div>
            <!-- END OPTION -->
            <!-- START OPTION -->
            <div class="br-option-item">
                <div class="br-label">
                    <div class="br-option-text">
						<?php esc_html_e( 'JS Files With Deferred Loading', 'breeze' ); ?>
                    </div>
                </div>
                <div class="br-option">
                    <div class="breeze-list-url">
						<?php if ( ! empty( $options['breeze-defer-js'] ) ) : ?>
							<?php foreach ( $options['breeze-defer-js'] as $js_url ) : ?>
                                <div class="breeze-input-group">

                                    <input type="text" size="98"
                                           class="breeze-input-url"
                                           name="defer-js[]"
                                           placeholder="<?php esc_attr_e( 'Enter URL...', 'breeze' ); ?>"
                                           value="<?php echo esc_html( $js_url ); ?>"/>
                                    <span class="sort-handle">
										<span class="dashicons dashicons-arrow-up moveUp"></span>
										<span class="dashicons dashicons-arrow-down moveDown"></span>
									</span>
                                    <span class="dashicons dashicons-no item-remove" title="<?php esc_attr_e( 'Remove', 'breeze' ); ?>"></span>
                                </div>
							<?php endforeach; ?>
						<?php else : ?>
                            <div class="breeze-input-group">
                                <input type="text" size="98"
                                       class="breeze-input-url"
                                       name="defer-js[]"
                                       id="defer-js"
                                       placeholder="<?php esc_attr_e( 'Enter URL...', 'breeze' ); ?>"
                                       value=""/>
                                <span class="sort-handle">
									<span class="dashicons dashicons-arrow-up moveUp"></span>
									<span class="dashicons dashicons-arrow-down moveDown"></span>
								</span>
                                <span class="dashicons dashicons-no item-remove" title="<?php esc_attr_e( 'Remove', 'breeze' ); ?>"></span>
                            </div>
						<?php endif; ?>
                    </div>
                    <div style="margin: 10px 0">
                        <button type="button" class="br-blue-button-reverse add-url" id="add-defer-js">
							<?php esc_html_e( 'Add URL', 'breeze' ); ?>
                        </button>
                    </div>
                    <div class="br-note">
                        <p class="br-important">
							<?php
							echo '<strong>';
							esc_html_e( 'Important: ', 'breeze' );
							echo '</strong>';
							esc_html_e( 'You should add the URL of original files as URL of minified files are not supported.', 'breeze' );
							?>
                        </p>
                    </div>
                </div>
            </div>
            <!-- END OPTION -->


        </div><!-- END GROUP -->

    </section>
    <div class="br-submit">
        <input type="submit" value="<?php echo esc_attr__( 'Save Changes', 'breeze' ); ?>" class="br-submit-save"/>
    </div>
</form>
