<?php
/**
 * Setup Wizard — Standalone page (no sidebar/topbar).
 *
 * Variables available: $registry
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_activated     = \Optee\Plugin\Activation::is_activated();
$is_email_verified = get_option( 'optee_email_verified', '' ) === '1';
$current_step     = (int) get_option( 'optee_setup_step', 1 );
$cron_url         = \Optee\Plugin\CronHandler::get_cron_url( true );
$admin_email      = get_option( 'admin_email', '' );
$dashboard_url    = \Optee\Plugin\Shortcode::get_url();

$current_user  = wp_get_current_user();
$prefill_name  = trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name;
$prefill_email = $current_user->user_email;

$wp_tz_string   = get_option( 'timezone_string', '' );
$wp_gmt_offset  = get_option( 'gmt_offset', 0 );
if ( $wp_tz_string ) {
    $wp_tz_label = $wp_tz_string;
    $wp_tz = new \DateTimeZone( $wp_tz_string );
} else {
    $offset_hours = (float) $wp_gmt_offset;
    $wp_tz_label = 'UTC' . ( $offset_hours >= 0 ? '+' : '' ) . $offset_hours;
    $sign = $offset_hours >= 0 ? '+' : '-';
    $abs = abs( $offset_hours );
    $h = intval( $abs );
    $m = ( $abs - $h ) * 60;
    $wp_tz = new \DateTimeZone( sprintf( '%s%02d:%02d', $sign, $h, $m ) );
}
$wp_now = new \DateTime( 'now', $wp_tz );
$wp_offset_sec = $wp_tz->getOffset( $wp_now );

// Cron last auto timestamp.
$cron_last_auto = get_option( 'optee_cron_last_auto', '' );
$cron_confirmed = $cron_last_auto && ( time() - (int) $cron_last_auto ) < 300;

// Hosting email template.
$hosting_email_body = "Hello,\n\nI would like to set up a cron job on my hosting account. Please add the following cron job to run every minute:\n\nCommand:\nwget -q --no-check-certificate -O /dev/null '" . $cron_url . "' >/dev/null 2>&1\n\nSchedule: Every 1 minute (* * * * *)\n\nThank you.";
?>
<style>@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>
<div id="optee-wizard" style="min-height:100vh;background:#f9fafb;display:flex;flex-direction:column;align-items:center;padding:48px 16px;">
    <!-- Logo -->
    <img src="<?php echo esc_url( OPTEE_PLUGIN_URL . 'assets/images/optee-logo-light.svg' ); ?>" alt="Optee" style="height:32px;margin-bottom:32px;">

    <!-- Step Indicator -->
    <div id="wizard-steps-indicator" style="display:flex;align-items:center;gap:8px;margin-bottom:32px;">
        <?php for ( $i = 1; $i <= 6; $i++ ) : ?>
            <div class="wizard-dot" data-step="<?php echo $i; ?>" style="width:10px;height:10px;border-radius:50%;background:<?php echo $i === 1 ? '#2aa5ff' : '#d1d5db'; ?>;transition:background .2s;"></div>
            <?php if ( $i < 6 ) : ?>
                <div style="width:24px;height:2px;background:#e5e7eb;border-radius:1px;"></div>
            <?php endif; ?>
        <?php endfor; ?>
    </div>

    <!-- Card Container -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.04);width:100%;max-width:520px;overflow:hidden;">

        <!-- ═══════════ Step 1: Activation ═══════════ -->
        <div class="wizard-step" data-step="1" style="padding:32px;">
            <div style="text-align:center;margin-bottom:24px;">
                <div style="width:48px;height:48px;border-radius:12px;background:#eff6ff;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2aa5ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <h2 style="font-size:18px;font-weight:700;color:#111827;margin:0;">Activate Optee</h2>
                <p style="font-size:14px;color:#6b7280;margin-top:6px;">Enter your license key to unlock the dashboard and automation.</p>
            </div>

            <?php if ( $is_activated ) : ?>
                <div style="display:flex;align-items:center;gap:10px;padding:16px;background:#f0fdf4;border:1px solid #86efac;border-radius:12px;margin-bottom:16px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    <span style="font-size:14px;color:#15803d;font-weight:500;">License already activated.</span>
                </div>
                <button type="button" class="optee-btn-primary wizard-next-btn" data-next="2" style="width:100%;justify-content:center;">Continue</button>
            <?php /* ACTIVATED STATE - END */ else : ?>
                <form id="wizard-activation-form" style="display:flex;flex-direction:column;gap:12px;">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:4px;">License Key</label>
                        <input type="text" id="wizard-act-key" class="optee-input" placeholder="OPTEE-XXXXX-XXXXX-XXXXX-XXXXX" required
                               style="text-transform:uppercase;letter-spacing:.05em;font-family:monospace;width:100%;">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:4px;">Your Name</label>
                        <input type="text" id="wizard-act-name" class="optee-input" placeholder="John Doe" required style="width:100%;"
                               value="<?php echo esc_attr( $prefill_name ); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:4px;">Email Address</label>
                        <input type="email" id="wizard-act-email" class="optee-input" placeholder="john@example.com" required style="width:100%;"
                               value="<?php echo esc_attr( $prefill_email ); ?>">
                    </div>
                    <div id="wizard-act-error" style="display:none;font-size:14px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;"></div>
                    <button type="submit" id="wizard-act-btn" class="optee-btn-primary" style="width:100%;justify-content:center;margin-top:4px;">Activate</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- ═══════════ Step 2: System Check ═══════════ -->
        <div class="wizard-step" data-step="2" style="display:none;padding:32px;">
            <div style="text-align:center;margin-bottom:24px;">
                <div style="width:48px;height:48px;border-radius:12px;background:#f0f9ff;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0369a1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="m9 12 2 2 4-4"/></svg>
                </div>
                <h2 style="font-size:18px;font-weight:700;color:#111827;margin:0;">System Check</h2>
                <p style="font-size:14px;color:#6b7280;margin-top:6px;">Verifying that everything is set up correctly.</p>
            </div>

            <div id="wizard-syscheck-loading" style="text-align:center;padding:16px;color:#6b7280;font-size:13px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;animation:spin 1s linear infinite;margin-right:6px;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                Running checks...
            </div>

            <div id="wizard-syscheck-results" style="display:none;flex-direction:column;gap:6px;margin-bottom:20px;"></div>

            <div id="wizard-syscheck-summary" style="display:none;margin-bottom:20px;"></div>

            <div style="display:flex;gap:8px;">
                <button type="button" id="wizard-syscheck-rerun" class="optee-btn-secondary" style="flex:1;justify-content:center;display:none;">Re-check</button>
                <button type="button" class="optee-btn-primary wizard-next-btn" data-next="3" style="flex:1;justify-content:center;">Continue</button>
            </div>
        </div>

        <!-- ═══════════ Step 3: Timezone ═══════════ -->
        <div class="wizard-step" data-step="3" style="display:none;padding:32px;">
            <div style="text-align:center;margin-bottom:24px;">
                <div style="width:48px;height:48px;border-radius:12px;background:#f0fdf4;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <h2 style="font-size:18px;font-weight:700;color:#111827;margin:0;">Timezone</h2>
                <p style="font-size:14px;color:#6b7280;margin-top:6px;">Make sure WordPress timezone matches your browser for accurate report times.</p>
            </div>

            <div id="wizard-tz-card" data-wp-offset="<?php echo intval( $wp_offset_sec ); ?>" style="margin-bottom:20px;">
                <div style="font-size:14px;color:#374151;display:flex;flex-direction:column;gap:6px;">
                    <div><span style="font-weight:500;display:inline-block;width:80px;">System:</span> <span id="wizard-tz-wp-label"><?php echo esc_html( $wp_tz_label ); ?></span> <span style="color:#9ca3af;margin-left:4px;" id="wizard-tz-wp-time"><?php echo esc_html( $wp_now->format( 'H:i:s' ) ); ?></span></div>
                    <div><span style="font-weight:500;display:inline-block;width:80px;">Browser:</span> <span id="wizard-tz-browser-label">—</span> <span style="color:#9ca3af;margin-left:4px;" id="wizard-tz-browser-time"></span></div>
                </div>

                <div id="wizard-tz-warning" style="display:none;margin-top:16px;padding:12px 16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;font-size:13px;color:#92400e;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:4px;"><path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                    Timezone mismatch detected. Click below to fix.
                    <div style="margin-top:8px;">
                        <button type="button" id="wizard-tz-fix-btn" class="optee-btn-primary optee-btn-sm">Set to <span id="wizard-tz-fix-label"></span></button>
                    </div>
                </div>

                <div id="wizard-tz-match" style="display:none;margin-top:16px;align-items:center;gap:8px;padding:12px 16px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:13px;color:#15803d;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    Timezones match — reports will show your local time.
                </div>
            </div>

            <div style="display:flex;gap:8px;">
                <button type="button" class="optee-btn-secondary wizard-next-btn" data-next="4" style="flex:1;justify-content:center;">Skip</button>
                <button type="button" class="optee-btn-primary wizard-next-btn" data-next="4" style="flex:1;justify-content:center;">Continue</button>
            </div>
        </div>

        <!-- ═══════════ Step 4: Cron Setup ═══════════ -->
        <div class="wizard-step" data-step="4" style="display:none;padding:32px;">
            <div style="text-align:center;margin-bottom:24px;">
                <div style="width:48px;height:48px;border-radius:12px;background:#faf5ff;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="10"/></svg>
                </div>
                <h2 style="font-size:18px;font-weight:700;color:#111827;margin:0;">Cron Setup</h2>
                <p style="font-size:14px;color:#6b7280;margin-top:6px;">Optee needs a cron job to run automation rules automatically.</p>
            </div>

            <!-- Cron confirmed state -->
            <div id="wizard-cron-confirmed" style="<?php echo $cron_confirmed ? '' : 'display:none;'; ?>margin-bottom:20px;">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:#f0fdf4;border:1px solid #86efac;border-radius:12px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        <span style="font-size:14px;color:#15803d;font-weight:500;">Cron is working!</span>
                    </div>
                    <button type="button" id="wizard-cron-reconfigure" style="font-size:12px;color:#2aa5ff;background:none;border:none;cursor:pointer;font-weight:500;">Reconfigure</button>
                </div>
            </div>

            <!-- Cron option tabs -->
            <div id="wizard-cron-tabs" style="<?php echo $cron_confirmed ? 'display:none;' : ''; ?>margin-bottom:20px;">
                <div style="display:flex;border-bottom:1px solid #e5e7eb;margin-bottom:16px;gap:0;">
                    <button type="button" class="wizard-cron-tab active" data-tab="cpanel" style="padding:8px 14px;font-size:13px;font-weight:500;border:none;background:none;cursor:pointer;color:#2aa5ff;border-bottom:2px solid #2aa5ff;">cPanel</button>
                    <button type="button" class="wizard-cron-tab" data-tab="directadmin" style="padding:8px 14px;font-size:13px;font-weight:500;border:none;background:none;cursor:pointer;color:#6b7280;border-bottom:2px solid transparent;">DirectAdmin</button>
                    <button type="button" class="wizard-cron-tab" data-tab="manual" style="padding:8px 14px;font-size:13px;font-weight:500;border:none;background:none;cursor:pointer;color:#6b7280;border-bottom:2px solid transparent;">Manual</button>
                    <button type="button" class="wizard-cron-tab" data-tab="email" style="padding:8px 14px;font-size:13px;font-weight:500;border:none;background:none;cursor:pointer;color:#6b7280;border-bottom:2px solid transparent;">Email Hosting</button>
                    <button type="button" class="wizard-cron-tab" data-tab="freecron" style="padding:8px 14px;font-size:13px;font-weight:500;border:none;background:none;cursor:pointer;color:#6b7280;border-bottom:2px solid transparent;">Free Cron Job</button>
                </div>

                <!-- cPanel tab -->
                <div class="wizard-cron-panel" data-tab="cpanel">
                    <p style="font-size:13px;color:#6b7280;margin-bottom:12px;">Automatically set up cron via your cPanel account.</p>
                    <div id="wizard-cpanel-detect" style="font-size:13px;color:#9ca3af;margin-bottom:12px;">Checking cPanel availability...</div>
                    <form id="wizard-cpanel-form" style="display:none;flex-direction:column;gap:10px;">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:4px;">cPanel Username</label>
                            <input type="text" id="wizard-cpanel-user" class="optee-input" placeholder="username" required style="width:100%;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:4px;">cPanel Password</label>
                            <input type="password" id="wizard-cpanel-pass" class="optee-input" placeholder="password" required style="width:100%;">
                        </div>
                        <div id="wizard-cpanel-url-row" style="display:none;">
                            <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:4px;">cPanel URL <span style="font-weight:400;color:#9ca3af;">(if not on localhost)</span></label>
                            <input type="text" id="wizard-cpanel-url" class="optee-input" placeholder="https://cpanel.example.com:2083" style="width:100%;">
                        </div>
                        <div id="wizard-cpanel-error" style="display:none;font-size:13px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px;"></div>
                        <button type="submit" id="wizard-cpanel-btn" class="optee-btn-primary optee-btn-sm" style="align-self:flex-start;">Set Up Cron</button>
                    </form>
                    <div id="wizard-cpanel-unavailable" style="display:none;font-size:13px;color:#92400e;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:12px;">
                        cPanel not detected on this server. Use the Manual or Email option instead.
                    </div>
                </div>

                <!-- DirectAdmin tab -->
                <div class="wizard-cron-panel" data-tab="directadmin" style="display:none;">
                    <p style="font-size:13px;color:#6b7280;margin-bottom:12px;">Set up cron via your DirectAdmin panel.</p>

                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:4px;">Your DirectAdmin URL <span style="font-weight:400;color:#9ca3af;">(e.g. https://hostname:2222)</span></label>
                        <input type="text" id="wizard-da-url" class="optee-input" placeholder="https://hostname:2222" style="width:100%;">
                    </div>

                    <a href="#" id="wizard-da-open" target="_blank" rel="noopener noreferrer" class="optee-btn-primary optee-btn-sm" style="display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        Open DirectAdmin Cron Jobs
                    </a>

                    <p style="font-size:13px;color:#6b7280;margin-bottom:6px;">In DirectAdmin, set all time fields (Minute, Hour, Day of Month, Month, Day of Week) to <strong>*</strong> and paste this command:</p>
                    <div style="position:relative;margin-bottom:14px;">
                        <pre id="wizard-da-command" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:12px;font-size:12px;white-space:pre-wrap;word-break:break-all;margin:0;">wget -q --no-check-certificate -O /dev/null '<?php echo esc_html( $cron_url ); ?>' >/dev/null 2>&1</pre>
                        <button type="button" id="wizard-da-copy" style="position:absolute;top:8px;right:8px;background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;color:#374151;">Copy</button>
                    </div>

                    <p style="font-size:13px;color:#6b7280;margin-bottom:8px;">Then click <strong>Create</strong> in DirectAdmin and verify below:</p>
                    <button type="button" id="wizard-da-verify" class="optee-btn-primary optee-btn-sm">I've Added It — Verify</button>
                    <div id="wizard-da-poll-status" style="display:none;margin-top:10px;font-size:13px;color:#6b7280;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;animation:spin 1s linear infinite;margin-right:4px;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                        Waiting for cron to run... <span id="wizard-da-countdown"></span>
                    </div>
                </div>

                <!-- Manual tab -->
                <div class="wizard-cron-panel" data-tab="manual" style="display:none;">
                    <p style="font-size:13px;color:#6b7280;margin-bottom:8px;">Full cron line (schedule + command):</p>
                    <div style="position:relative;margin-bottom:12px;">
                        <pre id="wizard-cron-command" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:12px;font-size:12px;white-space:pre-wrap;word-break:break-all;margin:0;">* * * * * wget -q --no-check-certificate -O /dev/null '<?php echo esc_html( $cron_url ); ?>' >/dev/null 2>&1</pre>
                        <button type="button" id="wizard-copy-cron" style="position:absolute;top:8px;right:8px;background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;color:#374151;">Copy</button>
                    </div>
                    <p style="font-size:13px;color:#6b7280;margin-bottom:8px;">Command only (for cPanel — set schedule to "Once Per Minute" separately):</p>
                    <div style="position:relative;margin-bottom:12px;">
                        <pre id="wizard-cron-command-only" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:12px;font-size:12px;white-space:pre-wrap;word-break:break-all;margin:0;">wget -q --no-check-certificate -O /dev/null '<?php echo esc_html( $cron_url ); ?>' >/dev/null 2>&1</pre>
                        <button type="button" id="wizard-copy-cron-cmd" style="position:absolute;top:8px;right:8px;background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;color:#374151;">Copy</button>
                    </div>
                    <details style="margin-bottom:12px;">
                        <summary style="font-size:12px;color:#2aa5ff;cursor:pointer;font-weight:500;user-select:none;">Show cPanel example</summary>
                        <div style="margin-top:8px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                            <img src="<?php echo esc_url( OPTEE_PLUGIN_URL . 'assets/images/wizard/cpanel-cron-example.png' ); ?>" alt="cPanel cron job settings example" style="width:100%;display:block;">
                        </div>
                        <p style="font-size:11px;color:#9ca3af;margin-top:6px;">In cPanel → Cron Jobs, select "Once Per Minute" and paste the command above.</p>
                    </details>
                    <button type="button" id="wizard-cron-verify" class="optee-btn-primary optee-btn-sm">I've Added It — Verify</button>
                    <div id="wizard-cron-poll-status" style="display:none;margin-top:10px;font-size:13px;color:#6b7280;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;animation:spin 1s linear infinite;margin-right:4px;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                        Waiting for cron to run... <span id="wizard-cron-countdown"></span>
                    </div>
                </div>

                <!-- Email tab -->
                <div class="wizard-cron-panel" data-tab="email" style="display:none;">
                    <p style="font-size:13px;color:#6b7280;margin-bottom:12px;">Copy this email and send it to your hosting provider's support:</p>
                    <textarea id="wizard-email-template" class="optee-input" rows="8" readonly style="width:100%;font-size:12px;line-height:1.5;resize:none;"><?php echo esc_textarea( $hosting_email_body ); ?></textarea>
                    <div style="display:flex;gap:8px;margin-top:10px;">
                        <button type="button" id="wizard-copy-email" class="optee-btn-secondary optee-btn-sm">Copy Email</button>
                        <button type="button" id="wizard-email-verify" class="optee-btn-primary optee-btn-sm">I Sent It — Verify</button>
                    </div>
                    <p style="font-size:12px;color:#6b7280;margin-top:16px;">Command only (for cPanel — set schedule to "Once Per Minute" separately):</p>
                    <div style="position:relative;">
                        <pre style="background:#f1f5f9;border-radius:8px;padding:10px 12px;font-size:11px;line-height:1.5;overflow-x:auto;margin:0;word-break:break-all;white-space:pre-wrap;"><?php echo esc_html( "wget -q --no-check-certificate -O /dev/null '" . $cron_url . "' >/dev/null 2>&1" ); ?></pre>
                        <button type="button" id="wizard-copy-command" class="optee-btn-secondary optee-btn-sm" style="position:absolute;top:6px;right:6px;font-size:11px;padding:2px 8px;">Copy</button>
                    </div>
                    <div id="wizard-email-poll-status" style="display:none;margin-top:10px;font-size:13px;color:#6b7280;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;animation:spin 1s linear infinite;margin-right:4px;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                        Waiting for cron to run... <span id="wizard-email-countdown"></span>
                    </div>
                </div>

                <!-- Free Cron Job tab -->
                <div class="wizard-cron-panel" data-tab="freecron" style="display:none;">
                    <p style="font-size:13px;color:#6b7280;margin-bottom:16px;">Use the free service <strong>cron-job.org</strong> to hit your Optee cron URL every minute. No install needed.</p>
                    <ol style="font-size:13px;color:#374151;margin:0 0 16px 0;padding-left:20px;line-height:2;">
                        <li>Go to <a href="https://cron-job.org" target="_blank" rel="noopener noreferrer" style="color:#2aa5ff;text-decoration:none;font-weight:600;">cron-job.org</a> and create a free account.</li>
                        <li>Click "<strong>Create cronjob</strong>".</li>
                        <li>Set <strong>Title</strong> to <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:12px;">Optee Cron</code>.</li>
                        <li>Paste this URL:</li>
                    </ol>
                    <div style="position:relative;margin-bottom:16px;">
                        <pre id="wizard-freecron-url" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:12px;font-size:12px;white-space:pre-wrap;word-break:break-all;margin:0;"><?php echo esc_html( $cron_url ); ?></pre>
                        <button type="button" id="wizard-freecron-copy" style="position:absolute;top:8px;right:8px;background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;color:#374151;">Copy</button>
                    </div>
                    <ol start="5" style="font-size:13px;color:#374151;margin:0 0 16px 0;padding-left:20px;line-height:2;">
                        <li>Set <strong>Schedule</strong> to <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:12px;">Every 1 minute</code>.</li>
                        <li>Click "<strong>Create</strong>" and come back here.</li>
                    </ol>
                    <button type="button" id="wizard-freecron-verify" class="optee-btn-primary optee-btn-sm">I've Set It Up — Verify</button>
                    <div id="wizard-freecron-poll-status" style="display:none;margin-top:10px;font-size:13px;color:#6b7280;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;animation:spin 1s linear infinite;margin-right:4px;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                        Waiting for cron to run... <span id="wizard-freecron-countdown"></span>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:8px;">
                <button type="button" class="optee-btn-secondary wizard-next-btn" data-next="5" style="flex:1;justify-content:center;">Skip</button>
                <button type="button" class="optee-btn-primary wizard-next-btn" data-next="5" style="flex:1;justify-content:center;">Continue</button>
            </div>
        </div>

        <!-- ═══════════ Step 5: Email Verification ═══════════ -->
        <div class="wizard-step" data-step="5" style="display:none;padding:32px;">
            <div style="text-align:center;margin-bottom:24px;">
                <div style="width:48px;height:48px;border-radius:12px;background:#fef3c7;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                </div>
                <h2 style="font-size:18px;font-weight:700;color:#111827;margin:0;">Email Verification</h2>
                <p style="font-size:14px;color:#6b7280;margin-top:6px;">Verify that your server can send emails. This is needed for rule notifications.</p>
            </div>

            <?php if ( $is_email_verified ) : ?>
                <div style="display:flex;align-items:center;gap:10px;padding:16px;background:#f0fdf4;border:1px solid #86efac;border-radius:12px;margin-bottom:16px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    <span style="font-size:14px;color:#15803d;font-weight:500;">Email already verified.</span>
                </div>
            <?php else : ?>
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:4px;">Email Address</label>
                    <input type="email" id="wizard-verify-email" class="optee-input" value="<?php echo esc_attr( $admin_email ); ?>" style="width:100%;">
                </div>
                <div id="wizard-email-sent" style="display:none;margin-bottom:12px;font-size:13px;color:#6b7280;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;animation:spin 1s linear infinite;margin-right:4px;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                    Verification email sent! Click the link in your inbox. <span id="wizard-email-verify-countdown"></span>
                </div>
                <div id="wizard-email-verified-msg" style="display:none;margin-bottom:12px;align-items:center;gap:8px;padding:12px 16px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:13px;color:#15803d;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    Email verified successfully!
                </div>
                <button type="button" id="wizard-send-verify" class="optee-btn-primary optee-btn-sm">Send Verification Email</button>
            <?php endif; ?>

            <div style="display:flex;gap:8px;margin-top:16px;">
                <button type="button" class="optee-btn-secondary wizard-next-btn" data-next="6" style="flex:1;justify-content:center;">Skip</button>
                <button type="button" class="optee-btn-primary wizard-next-btn" data-next="6" style="flex:1;justify-content:center;">Continue</button>
            </div>
        </div>

        <!-- ═══════════ Step 6: Done ═══════════ -->
        <div class="wizard-step" data-step="6" style="display:none;padding:32px;">
            <div style="text-align:center;margin-bottom:24px;">
                <div style="width:48px;height:48px;border-radius:12px;background:#f0fdf4;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </div>
                <h2 style="font-size:18px;font-weight:700;color:#111827;margin:0;">You're All Set!</h2>
                <p style="font-size:14px;color:#6b7280;margin-top:6px;">Here's a summary of your setup.</p>
            </div>

            <div id="wizard-summary" style="display:flex;flex-direction:column;gap:8px;margin-bottom:24px;">
                <!-- Populated by JS -->
            </div>

            <button type="button" id="wizard-complete-btn" class="optee-btn-primary" style="width:100%;justify-content:center;">
                Go to Dashboard
            </button>
        </div>

    </div>

    <!-- Step counter -->
    <div style="margin-top:16px;font-size:12px;color:#9ca3af;">
        Step <span id="wizard-step-num">1</span> of 6
    </div>
</div>
