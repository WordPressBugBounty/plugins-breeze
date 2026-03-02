/**
 * Breeze One-Click Optimization JavaScript
 */
(function ($) {

    var levelGlobal = '';
    // Initialize the One-Click Optimization functionality
    $(document).ready(function () {
        // Apply optimization button click handler
        $(document).on('click', '.apply-optimization', function (e) {
            e.preventDefault();

            const level = $(this).data('level');
            levelGlobal = level;
            const confirmMessage = breeze_one_click_strings.confirm_apply.replace('%s', level);

            if (confirm(confirmMessage)) {
                // First check compatibility
                checkCompatibility(level);
            }
        });

        // Restore settings button click handler
        $(document).on('click', '#restore-settings-button', function (e) {
            e.preventDefault();

            const confirmMessage = breeze_one_click_strings.confirm_restore;

            if (confirm(confirmMessage)) {
                restoreSettings();
            }
        });

        // Confirm compatibility
        $(document).on('click', 'button.optimization-continue', function () {
            var $noticeEl = $('#breeze-compatibility-notice');
            $noticeEl.hide();
            $('.optimization-levels').removeClass('detected-compatibility-issue');
            applyOptimization(levelGlobal, true);
        });

        // Cancel compatibility
        $(document).on('click', 'button.optimization-cancel', function () {
            var $noticeEl = $('#breeze-compatibility-notice');
            $noticeEl.hide();
            $('.optimization-levels').removeClass('detected-compatibility-issue');
        });

        // Handle explore optimization button
        $(document).on('click', '.explore-optimization', function (e) {
            e.preventDefault();
            $('#tab-one-click-optimization').trigger('click');
        });

        // Handle close notice button
        $(document).on('click', '.close-notice', function (e) {
            e.preventDefault();
            var $notice = $(this).closest('.breeze-top-notice');
            $notice.fadeOut(function () {
                $(this).remove();
            });
            // Set option to hide notice permanently
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'breeze_hide_optimization_notice',
                    nonce: breeze_token_name.breeze_save_options
                }
            });
        });
    });

    /**
     * Check compatibility for the selected optimization level
     *
     * @param {string} level Optimization level (basic, advanced, expert)
     */
    function checkCompatibility(level) {
        // Show loading state
        showLoading();

        // Hide any previous notices
        $('.optimization-notice').hide();

        // AJAX request to check compatibility
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'breeze_check_compatibility',
                level: level,
                nonce: breeze_token_name.breeze_check_compat
            },
            success: function (response) {
                if (response.success) {
                    if (response.data.issues && response.data.issues.length > 0) {
                        // Show compatibility issues
                        showCompatibilityIssues(response.data.issues, level);
                        hideLoading();
                    } else {
                        // No compatibility issues, proceed with optimization
                        applyOptimization(level, false);
                    }
                } else {
                    // Show error message
                    alert(response.data.message || 'An error occurred while checking compatibility.');
                }
            },
            error: function () {
                alert(breeze_one_click_strings.error_checking_compatibility);
                hideLoading();
            }
        });
    }

    /**
     * Show compatibility issues
     *
     * @param {Array} issues Array of compatibility issues
     * @param {string} level Optimization level (basic, advanced, expert)
     */
    function showCompatibilityIssues(issues, level) {
        $('.optimization-levels').addClass('detected-compatibility-issue');
        const $issuesList = $('#compatibility-issues-list');
        $issuesList.empty();

        // Add issues to the list
        issues.forEach(function (issue) {
            const $issue = $('<div class="compatibility-issue"></div>');
            const $title = $('<h4></h4>').text(issue.type === 'theme' ? 'Theme: ' + issue.name : 'Plugin: ' + issue.name);
            const $message = $('<p></p>').text(issue.message);

            $issue.append($title).append($message);
            $issuesList.append($issue);
        });

        var $noticeEl = $('#breeze-compatibility-notice');

        // Show compatibility notice
        $noticeEl.show();

        // Scroll to notice message
        $('html, body').animate({
            scrollTop: $noticeEl.offset().top - 100
        }, 500);
    }

    /**
     * Apply optimization
     *
     * @param {string} level Optimization level (basic, advanced, expert)
     * @param hadCompatibilityIssues
     */
    function applyOptimization(level, hadCompatibilityIssues) {
        // Show loading state
        hadCompatibilityIssues && showLoading();

        // Hide any previous notices
        $('.optimization-notice').hide();

        // AJAX request to apply optimization
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'breeze_apply_optimization',
                level: level,
                _wpnonce: breeze_token_name.breeze_apply_optimization,
                'is-network': $('body').hasClass('network-admin')
            },
            success: function (response) {

                if (response.success) {
                    var $success_section = $('#breeze-optimization-success');
                    // Show success message
                    $success_section.show();

                    // Scroll to success message
                    $('html, body').animate({
                        scrollTop: $success_section.offset().top - 100
                    }, 500);

                    // Update UI for applied level
                    updateUIForAppliedLevel(level);
                } else {
                    // Show error message

                    alert(response.data.message || breeze_one_click_strings.error_applying_optimization);
                }
            },
            error: function () {
                alert('An error occurred while applying optimization.');
            },
            complete: function () {
                hideLoading();
            }
        });
    }

    /**
     * Restore settings
     */
    function restoreSettings() {
        // Show loading state
        showLoading();

        // Hide any previous notices
        $('.optimization-notice').hide();

        // AJAX request to restore settings
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'breeze_restore_settings',
                nonce: breeze_token_name.breeze_restore_settings,
                'is-network': $('body').hasClass('network-admin')
            },
            success: function (response) {

                if (response.success) {
                    // Show success message with notification
                    $('#breeze-optimization-restore').show();

                    // Update UI for restored settings
                    updateUIForRestoredSettings();
                } else {
                    // Show error message
                    alert(response.data.message || breeze_one_click_strings.error_restoring_settings);
                }
            },
            error: function () {
                alert('An error occurred while restoring settings.');
            },
            complete: function () {
                hideLoading();
            }
        });
    }

    /**
     * Show loading state
     */
    function showLoading() {
        var loader_spinner_save = '<div class="br-loader-spinner saving_settings"><div></div><div></div><div></div><div></div></div>';
        var $html_area = $('.br-options');
        $html_area.find('.breeze-one-click-optimization-section').hide();
        $html_area.append(loader_spinner_save);
    }

    /**
     * Hide loading state
     */
    function hideLoading() {
        var $html_area = $('.br-options');
        $html_area.find('.br-loader-spinner.saving_settings').remove();
        $html_area.find('.breeze-one-click-optimization-section').show();
    }

    /**
     * Update UI for applied optimization level
     *
     * @param {string} level The applied optimization level
     */
    function updateUIForAppliedLevel(level) {
        // Remove applied class and applied-tag from all levels
        $('.optimization-level').removeClass('applied');
        $('.applied-tag').remove();

        // Add applied class to the specific level
        $('.optimization-level.' + level).addClass('applied');

        // Add applied-tag to the level header
        var $levelHeader = $('.optimization-level.' + level + ' .level-header h3');
        $levelHeader.append('<span class="applied-tag">' + breeze_one_click_strings.enabled + '</span>');

        // Add br-disabled class to the button of the applied level
        $('.optimization-level.' + level + ' .apply-optimization').addClass('br-disabled');

        // Remove br-disabled class from other buttons
        $('.optimization-level:not(.' + level + ') .apply-optimization').removeClass('br-disabled');

        // Show restore settings section
        $('.restore-settings').show();
    }

    /**
     * Update UI for restored settings
     */
    function updateUIForRestoredSettings() {
        // Remove applied class and applied-tag from all levels
        $('.optimization-level').removeClass('applied');
        $('.applied-tag').remove();

        // Remove br-disabled class from all buttons
        $('.apply-optimization').removeClass('br-disabled');

        // Hide restore settings section
        $('.restore-settings').hide();
    }

})(jQuery);
