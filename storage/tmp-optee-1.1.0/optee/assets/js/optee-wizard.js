(function($) {
    'use strict';

    var currentStep = parseInt(opteeWizard.currentStep, 10) || 1;
    var cronPollTimer = null;
    var emailPollTimer = null;

    // Track completion state across steps — seeded from server, updated by JS actions.
    var browserOffsetSec = -new Date().getTimezoneOffset() * 60;
    var completed = {
        activation: !!opteeWizard.isActivated,
        systemCheck: false,
        timezone: browserOffsetSec === parseInt(opteeWizard.wpOffsetSec, 10),
        cron: !!opteeWizard.isCronConfirmed,
        email: !!opteeWizard.isEmailVerified
    };

    // ── Step navigation ──

    function goToStep(step) {
        step = Math.max(1, Math.min(6, step));
        currentStep = step;

        $('.wizard-step').hide();
        $('.wizard-step[data-step="' + step + '"]').show();

        // Update dots.
        $('.wizard-dot').each(function() {
            var dotStep = parseInt($(this).data('step'), 10);
            $(this).css('background', dotStep <= step ? '#2aa5ff' : '#d1d5db');
        });

        $('#wizard-step-num').text(step);

        // Save step.
        Optee.Ajax.post('optee_wizard_save_step', { step: step });

        // Step-specific init.
        if (step === 2) initSystemCheck();
        if (step === 3) initTimezone();
        if (step === 4) initCron();
        if (step === 6) initSummary();
    }

    // Navigation buttons.
    $(document).on('click', '.wizard-next-btn', function() {
        var next = parseInt($(this).data('next'), 10);
        goToStep(next);
    });

    // ── Step 1: Activation ──

    $('#wizard-activation-form').on('submit', function(e) {
        e.preventDefault();
        var $error = $('#wizard-act-error').hide();
        var $btn = $('#wizard-act-btn').prop('disabled', true).text('Activating...');

        var key   = $('#wizard-act-key').val().trim().toUpperCase();
        var name  = $('#wizard-act-name').val().trim();
        var email = $('#wizard-act-email').val().trim();

        Optee.Ajax.post('optee_validate_activation_key', {
            key: key, name: name, email: email
        }).done(function(res) {
            if (res.success) {
                completed.activation = true;
                Optee.Toast.show('License activated!', 'success');
                // Replace form with success + continue.
                var $step = $('.wizard-step[data-step="1"]');
                $step.find('form').replaceWith(
                    '<div style="display:flex;align-items:center;gap:10px;padding:16px;background:#f0fdf4;border:1px solid #86efac;border-radius:12px;margin-bottom:16px;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>' +
                    '<span style="font-size:14px;color:#15803d;font-weight:500;">License activated!</span>' +
                    '</div>' +
                    '<button type="button" class="optee-btn-primary wizard-next-btn" data-next="2" style="width:100%;justify-content:center;">Continue</button>'
                );
            } else {
                $error.text(res.data.message || 'Activation failed.').show();
                $btn.prop('disabled', false).text('Activate');
            }
        }).fail(function() {
            $error.text('Network error. Please try again.').show();
            $btn.prop('disabled', false).text('Activate');
        });
    });

    // Auto-format key input.
    $(document).on('input', '#wizard-act-key', function() {
        var val = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
        if (val.length > 0 && !val.startsWith('OPTEE') && !val.startsWith('O')) {
            val = 'OPTEE-' + val;
        }
        this.value = val;
    });

    // ── Step 2: System Check ──

    var sysCheckLabels = [
        'Dashboard page',
        'Homepage set to dashboard',
        'Optee theme files',
        'Optee theme active',
        'Database tables',
        'Cron secret token',
        'WP-Cron disabled',
        'PHP version',
        'OpenSSL extension'
    ];

    var pendingIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/></svg>';
    var spinnerIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2aa5ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation:spin .8s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>';
    var passIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>';
    var failIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';
    var warnIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';

    var sysCheckRan = false;
    function initSystemCheck() {
        if (sysCheckRan) return;
        runSystemCheck();
    }

    function runSystemCheck() {
        sysCheckRan = true;
        var $loading = $('#wizard-syscheck-loading').hide();
        var $results = $('#wizard-syscheck-results').empty();
        var $summary = $('#wizard-syscheck-summary').hide().empty();
        var $rerun = $('#wizard-syscheck-rerun').hide();

        // Show all checks as pending.
        for (var p = 0; p < sysCheckLabels.length; p++) {
            $results.append(
                '<div class="wizard-check-row" data-index="' + p + '" style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;transition:all .2s;">' +
                '<span class="wizard-check-icon">' + pendingIcon + '</span>' +
                '<div style="flex:1;min-width:0;">' +
                '<div class="wizard-check-label" style="font-size:13px;font-weight:500;color:#9ca3af;">' + Optee.Util.escapeHtml(sysCheckLabels[p]) + '</div>' +
                '<div class="wizard-check-detail" style="font-size:11px;color:#d1d5db;">Waiting...</div>' +
                '</div>' +
                '</div>'
            );
        }
        $results.css('display', 'flex');

        // Fetch all results in one call, then reveal one by one.
        Optee.Ajax.post('optee_wizard_system_check', {}).done(function(res) {
            if (!res.success) {
                $results.find('.wizard-check-detail').text('Check failed.');
                return;
            }

            var checks = res.data.checks;
            var passCount = res.data.pass_count;
            var total = res.data.total;
            var hasFails = false;
            var delay = 0;

            for (var i = 0; i < checks.length; i++) {
                (function(idx) {
                    var c = checks[idx];
                    // Show spinner briefly, then result.
                    setTimeout(function() {
                        var $row = $results.find('.wizard-check-row[data-index="' + idx + '"]');
                        $row.find('.wizard-check-icon').html(spinnerIcon);
                        $row.find('.wizard-check-detail').text('Checking...').css('color', '#6b7280');
                        $row.find('.wizard-check-label').css('color', '#374151');
                    }, delay);

                    setTimeout(function() {
                        var $row = $results.find('.wizard-check-row[data-index="' + idx + '"]');
                        var icon, color, bg;

                        if (c.status === 'pass') {
                            icon = passIcon; bg = '#f0fdf4'; color = '#15803d';
                        } else if (c.status === 'warn') {
                            icon = warnIcon; bg = '#fffbeb'; color = '#92400e';
                        } else if (c.status === 'skip') {
                            icon = pendingIcon; bg = '#f9fafb'; color = '#6b7280';
                        } else {
                            icon = failIcon; bg = '#fef2f2'; color = '#991b1b';
                        }

                        $row.css({ background: bg });
                        $row.find('.wizard-check-icon').html(icon);
                        $row.find('.wizard-check-label').css('color', color);
                        $row.find('.wizard-check-detail').text(c.detail).css('color', '#6b7280');

                        // Add fix button if needed.
                        if (c.status !== 'pass' && c.status !== 'skip' && c.fix) {
                            if (!$row.find('.wizard-fix-btn').length) {
                                $row.append('<button type="button" class="wizard-fix-btn optee-btn-primary optee-btn-sm" data-fix="' + c.fix + '" style="font-size:11px;padding:2px 10px;margin-left:auto;flex-shrink:0;">Fix</button>');
                            }
                        }

                        // After last check, show summary.
                        if (idx === checks.length - 1) {
                            setTimeout(function() { showSysCheckSummary(checks, passCount, total); }, 150);
                        }
                    }, delay + 200);

                    delay += 250;
                })(i);

                if (checks[i].status === 'fail') hasFails = true;
            }

            function showSysCheckSummary(checks, passCount, total) {
                var hasFails = false;
                for (var j = 0; j < checks.length; j++) {
                    if (checks[j].status === 'fail') hasFails = true;
                }

                if (passCount === total) {
                    completed.systemCheck = true;
                    $summary.html(
                        '<div style="display:flex;align-items:center;gap:8px;padding:12px 16px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:13px;color:#15803d;">' +
                        passIcon +
                        'All ' + total + ' checks passed!' +
                        '</div>'
                    ).show();
                } else {
                    completed.systemCheck = !hasFails;
                    $summary.html(
                        '<div style="font-size:13px;color:#6b7280;">' + passCount + ' of ' + total + ' checks passed. ' +
                        (hasFails ? 'Fix the issues above or continue anyway.' : '') + '</div>'
                    ).show();
                    $rerun.show();
                }
            }

        }).fail(function() {
            $results.find('.wizard-check-icon').html(failIcon);
            $results.find('.wizard-check-detail').text('Failed to run checks.').css('color', '#dc2626');
        });
    }

    // Fix button handler.
    $(document).on('click', '.wizard-fix-btn', function() {
        var $btn = $(this);
        var fix = $btn.data('fix');
        $btn.prop('disabled', true).text('Fixing...');

        Optee.Ajax.post('optee_wizard_fix_issue', { issue: fix }).done(function(res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
                // Re-run checks after fix.
                sysCheckRan = false;
                runSystemCheck();
            } else {
                Optee.Toast.show(res.data.message || 'Fix failed.', 'error');
                $btn.prop('disabled', false).text('Fix');
            }
        }).fail(function() {
            Optee.Toast.show('Request failed.', 'error');
            $btn.prop('disabled', false).text('Fix');
        });
    });

    // Re-check button.
    $(document).on('click', '#wizard-syscheck-rerun', function() {
        sysCheckRan = false;
        runSystemCheck();
    });

    // ── Step 3: Timezone ──

    var tzInitialized = false;
    function initTimezone() {
        if (tzInitialized) return;
        tzInitialized = true;

        var card = document.getElementById('wizard-tz-card');
        if (!card) return;

        var browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        var browserNow = new Date();
        var browserOffsetSec = -browserNow.getTimezoneOffset() * 60;
        var wpOffsetSec = parseInt(card.dataset.wpOffset, 10);

        $('#wizard-tz-browser-label').text(browserTz);
        $('#wizard-tz-browser-time').text(browserNow.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));

        if (browserOffsetSec === wpOffsetSec) {
            completed.timezone = true;
            $('#wizard-tz-match').css('display', 'flex');
            $('#wizard-tz-warning').hide();
        } else {
            $('#wizard-tz-warning').show();
            $('#wizard-tz-match').hide();
            $('#wizard-tz-fix-label').text(browserTz);
        }

        $('#wizard-tz-fix-btn').on('click', function() {
            var $btn = $(this).prop('disabled', true);
            Optee.Ajax.post('optee_save_timezone', { timezone: browserTz }).done(function(res) {
                if (res.success) {
                    completed.timezone = true;
                    Optee.Toast.show('Timezone updated!', 'success');
                    $('#wizard-tz-wp-label').text(res.data.timezone);
                    $('#wizard-tz-wp-time').text(res.data.server_time);
                    $('#wizard-tz-warning').hide();
                    $('#wizard-tz-match').css('display', 'flex');
                } else {
                    Optee.Toast.show(res.data.message || 'Failed.', 'error');
                }
                $btn.prop('disabled', false);
            }).fail(function() {
                Optee.Toast.show('Request failed.', 'error');
                $btn.prop('disabled', false);
            });
        });
    }

    // ── Step 3: Cron Setup ──

    var cronInitialized = false;
    function initCron() {
        if (cronInitialized) return;
        cronInitialized = true;

        // Check if already confirmed (from PHP).
        if ($('#wizard-cron-confirmed').css('display') !== 'none') {
            completed.cron = true;
        }

        // Detect cPanel.
        Optee.Ajax.post('optee_wizard_detect_cpanel', {}).done(function(res) {
            if (res.success && res.data.available) {
                $('#wizard-cpanel-detect').hide();
                $('#wizard-cpanel-form').css('display', 'flex');
            } else {
                $('#wizard-cpanel-detect').hide();
                $('#wizard-cpanel-unavailable').show();
            }
        }).fail(function() {
            $('#wizard-cpanel-detect').hide();
            $('#wizard-cpanel-unavailable').show();
        });

        // DirectAdmin: update link when URL field changes.
        $('#wizard-da-url').on('input', function() {
            var base = $(this).val().replace(/\/+$/, '');
            if (base) {
                $('#wizard-da-open').attr('href', base + '/evo/user/cronjobs/create');
            } else {
                $('#wizard-da-open').attr('href', '#');
            }
        });
    }

    // Cron tab switching.
    $(document).on('click', '.wizard-cron-tab', function() {
        var tab = $(this).data('tab');
        $('.wizard-cron-tab').css({ color: '#6b7280', 'border-bottom-color': 'transparent' }).removeClass('active');
        $(this).css({ color: '#2aa5ff', 'border-bottom-color': '#2aa5ff' }).addClass('active');
        $('.wizard-cron-panel').hide();
        $('.wizard-cron-panel[data-tab="' + tab + '"]').show();
    });

    // cPanel form submit.
    $('#wizard-cpanel-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#wizard-cpanel-btn').prop('disabled', true).text('Setting up...');
        var $error = $('#wizard-cpanel-error').hide();

        Optee.Ajax.post('optee_wizard_cpanel_cron', {
            username: $('#wizard-cpanel-user').val(),
            password: $('#wizard-cpanel-pass').val(),
            cpanel_url: $('#wizard-cpanel-url').val()
        }).done(function(res) {
            if (res.success) {
                Optee.Toast.show('Cron job added!', 'success');
                showCronConfirmed();
            } else {
                var msg = res.data.message || 'Failed.';
                // If localhost failed, show URL field.
                if (msg.indexOf('localhost') !== -1 || msg.indexOf('connect') !== -1) {
                    $('#wizard-cpanel-url-row').show();
                }
                $error.text(msg).show();
                $btn.prop('disabled', false).text('Set Up Cron');
            }
        }).fail(function() {
            $error.text('Network error.').show();
            $btn.prop('disabled', false).text('Set Up Cron');
        });
    });

    // DirectAdmin: copy command.
    $('#wizard-da-copy').on('click', function() {
        navigator.clipboard.writeText($('#wizard-da-command').text()).then(function() {
            Optee.Toast.show('Copied!', 'success');
        });
    });

    // DirectAdmin: verify cron.
    $('#wizard-da-verify').on('click', function() {
        startCronPoll($('#wizard-da-poll-status'), $('#wizard-da-countdown'));
    });

    // Copy cron command (full line).
    $('#wizard-copy-cron').on('click', function() {
        var text = $('#wizard-cron-command').text();
        navigator.clipboard.writeText(text).then(function() {
            Optee.Toast.show('Copied!', 'success');
        });
    });

    // Copy cron command only (no schedule).
    $('#wizard-copy-cron-cmd').on('click', function() {
        var text = $('#wizard-cron-command-only').text();
        navigator.clipboard.writeText(text).then(function() {
            Optee.Toast.show('Copied!', 'success');
        });
    });

    // Copy email template.
    $('#wizard-copy-email').on('click', function() {
        var text = $('#wizard-email-template').val();
        navigator.clipboard.writeText(text).then(function() {
            Optee.Toast.show('Copied!', 'success');
        });
    });

    $('#wizard-copy-command').on('click', function() {
        var pre = $(this).closest('div').find('pre').text();
        navigator.clipboard.writeText(pre).then(function() {
            Optee.Toast.show('Copied!', 'success');
        });
    });

    // Cron polling function.
    function startCronPoll($statusEl, $countdownEl) {
        var remaining = 90;
        $statusEl.show();
        $countdownEl.text('(' + remaining + 's)');

        cronPollTimer = setInterval(function() {
            remaining--;
            $countdownEl.text('(' + remaining + 's)');

            if (remaining % 5 === 0) {
                Optee.Ajax.post('optee_wizard_check_cron', {}).done(function(res) {
                    if (res.success && res.data.running) {
                        clearInterval(cronPollTimer);
                        showCronConfirmed();
                    }
                });
            }

            if (remaining <= 0) {
                clearInterval(cronPollTimer);
                $statusEl.html('<span style="color:#dc2626;">Cron was not detected. Please verify your setup and try again.</span>');
            }
        }, 1000);
    }

    $('#wizard-cron-verify').on('click', function() {
        startCronPoll($('#wizard-cron-poll-status'), $('#wizard-cron-countdown'));
    });

    $('#wizard-email-verify').on('click', function() {
        startCronPoll($('#wizard-email-poll-status'), $('#wizard-email-countdown'));
    });

    // Free Cron Job: copy URL.
    $('#wizard-freecron-copy').on('click', function() {
        navigator.clipboard.writeText($('#wizard-freecron-url').text()).then(function() {
            Optee.Toast.show('Copied!', 'success');
        });
    });

    // Free Cron Job: verify cron.
    $('#wizard-freecron-verify').on('click', function() {
        startCronPoll($('#wizard-freecron-poll-status'), $('#wizard-freecron-countdown'));
    });

    function showCronConfirmed() {
        completed.cron = true;
        $('#wizard-cron-tabs').hide();
        $('#wizard-cron-confirmed').show();
    }

    // Reconfigure cron — show tabs again.
    $(document).on('click', '#wizard-cron-reconfigure', function() {
        $('#wizard-cron-confirmed').hide();
        $('#wizard-cron-tabs').show();
    });

    // ── Step 4: Email Verification ──

    $('#wizard-send-verify').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Sending...');
        var email = $('#wizard-verify-email').val();

        Optee.Ajax.post('optee_wizard_send_verify_email', { email: email }).done(function(res) {
            if (res.success) {
                $btn.hide();
                $('#wizard-email-sent').show();
                startEmailPoll();
            } else {
                Optee.Toast.show(res.data.message || 'Failed to send.', 'error');
                $btn.prop('disabled', false).text('Send Verification Email');
            }
        }).fail(function() {
            Optee.Toast.show('Network error.', 'error');
            $btn.prop('disabled', false).text('Send Verification Email');
        });
    });

    function startEmailPoll() {
        var remaining = 120;
        var $countdown = $('#wizard-email-verify-countdown');
        $countdown.text('(' + remaining + 's)');

        emailPollTimer = setInterval(function() {
            remaining--;
            $countdown.text('(' + remaining + 's)');

            if (remaining % 3 === 0) {
                Optee.Ajax.post('optee_wizard_check_email', {}).done(function(res) {
                    if (res.success && res.data.verified) {
                        clearInterval(emailPollTimer);
                        completed.email = true;
                        $('#wizard-email-sent').hide();
                        $('#wizard-email-verified-msg').css('display', 'flex');
                        Optee.Toast.show('Email verified!', 'success');
                    }
                });
            }

            if (remaining <= 0) {
                clearInterval(emailPollTimer);
                $('#wizard-email-sent').html('<span style="color:#dc2626;">Verification timed out. The link will still work if you click it later.</span>');
            }
        }, 1000);
    }

    // ── Step 5: Summary ──

    function initSummary() {
        var $summary = $('#wizard-summary').empty();
        var items = [
            { label: 'Activation', key: 'activation' },
            { label: 'System Check', key: 'systemCheck' },
            { label: 'Timezone', key: 'timezone' },
            { label: 'Cron Setup', key: 'cron' },
            { label: 'Email Verification', key: 'email' }
        ];

        items.forEach(function(item) {
            var done = completed[item.key];
            var color = done ? '#22c55e' : '#d1d5db';
            var icon = done
                ? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' + color + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>'
                : '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' + color + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/></svg>';
            var label = '<span style="font-size:14px;color:' + (done ? '#111827' : '#9ca3af') + ';font-weight:500;">' + item.label + '</span>';
            var badge = done
                ? '<span style="font-size:11px;color:#15803d;background:#f0fdf4;border:1px solid #86efac;border-radius:9999px;padding:1px 8px;">Done</span>'
                : '<span style="font-size:11px;color:#9ca3af;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:9999px;padding:1px 8px;">Skipped</span>';

            $summary.append(
                '<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">' +
                icon + label + '<span style="flex:1;"></span>' + badge + '</div>'
            );
        });
    }

    // Complete wizard.
    $('#wizard-complete-btn').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Finishing...');
        Optee.Ajax.post('optee_wizard_complete', {}).done(function() {
            window.location.href = opteeWizard.dashboardUrl;
        }).fail(function() {
            window.location.href = opteeWizard.dashboardUrl;
        });
    });

    // ── Init ──

    // If ?restart=1 or setup already completed (re-run from Settings), start from step 1.
    var params = new URLSearchParams(window.location.search);
    if (params.get('restart') === '1' || opteeWizard.setupComplete) {
        currentStep = completed.activation ? 2 : 1;
    } else if (!opteeWizard.isActivated && currentStep > 1) {
        currentStep = 1;
    }
    // Handle old step numbers from before system check was added.
    if (currentStep > 6) currentStep = 6;
    goToStep(currentStep);

})(jQuery);
