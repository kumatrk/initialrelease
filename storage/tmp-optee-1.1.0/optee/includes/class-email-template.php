<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * HTML email template builder for Optee system notifications.
 *
 * All templates use table-based layout with inline styles for
 * maximum email client compatibility.
 */
class EmailTemplate {

    /**
     * Brand color.
     */
    private const BRAND = '#2aa5ff';

    /**
     * Task badge color map: task keyword => [bg, text].
     */
    private const TASK_COLORS = [
        'balance'  => [ '#eff6ff', '#1e40af' ],
        'earning'  => [ '#f0fdf4', '#166534' ],
        'vt'       => [ '#fef2f2', '#991b1b' ],
        'virus'    => [ '#fef2f2', '#991b1b' ],
        'rule'     => [ '#faf5ff', '#7e22ce' ],
        'update'   => [ '#f0f9ff', '#0369a1' ],
    ];

    /**
     * Notification type color map: type => [dot, bg, text, border].
     */
    private const TYPE_COLORS = [
        'info'    => [ '#2aa5ff', '#eff6ff', '#1e40af', '#93c5fd' ],
        'success' => [ '#22c55e', '#f0fdf4', '#166534', '#86efac' ],
        'warning' => [ '#f59e0b', '#fffbeb', '#92400e', '#fcd34d' ],
        'error'   => [ '#ef4444', '#fef2f2', '#991b1b', '#fca5a5' ],
    ];

    /**
     * Build the automation run email.
     *
     * @param array  $lines          Task result lines (same format as cron handler).
     * @param float  $total_duration Total run duration in seconds.
     * @param string $error          Optional error message.
     * @return string Full HTML email.
     */
    public static function automation_run( array $lines, float $total_duration, string $error = '' ): string {
        $date     = current_time( 'D, d M Y' );
        $time     = current_time( 'H:i:s' );
        $executed = 0;
        foreach ( $lines as $l ) {
            if ( empty( $l['skipped'] ) ) {
                $executed++;
            }
        }
        $skipped = count( $lines ) - $executed;

        // Build task rows.
        $rows_html = '';
        foreach ( $lines as $line ) {
            $is_skipped = ! empty( $line['skipped'] );
            $is_error   = isset( $line['success'] ) && ! $line['success'];
            $text_color = $is_skipped ? '#9ca3af' : ( $is_error ? '#dc2626' : '#374151' );
            $badge      = self::task_badge( $line['task'] );

            $rows_html .= '<tr>';
            $rows_html .= '<td style="padding:10px 12px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;font-variant-numeric:tabular-nums;white-space:nowrap;">' . esc_html( $line['time'] ) . '</td>';
            $rows_html .= '<td style="padding:10px 12px;border-bottom:1px solid #f3f4f6;">' . $badge . '</td>';
            $rows_html .= '<td style="padding:10px 12px;border-bottom:1px solid #f3f4f6;font-size:13px;color:' . $text_color . ';line-height:1.4;">' . esc_html( $line['detail'] ) . '</td>';
            $dur = $is_skipped ? '—' : esc_html( $line['duration'] );
            $rows_html .= '<td style="padding:10px 12px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap;">' . $dur . '</td>';
            $rows_html .= '</tr>';
        }

        // Status badge.
        if ( ! empty( $error ) ) {
            $status_badge = self::status_badge( 'error', $error );
        } else {
            $status_badge = self::status_badge( 'success', 'Completed in ' . $total_duration . 's' );
        }

        // Table or empty message.
        if ( ! empty( $error ) ) {
            $content = '<tr><td style="padding:24px;text-align:center;color:#6b7280;font-size:14px;">No tasks were executed.</td></tr>';
        } else {
            $content = '
            <tr><td style="padding:0;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                    <tr>
                        <th style="padding:8px 12px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;border-bottom:2px solid #e5e7eb;text-align:left;">Time</th>
                        <th style="padding:8px 12px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;border-bottom:2px solid #e5e7eb;text-align:left;">Task</th>
                        <th style="padding:8px 12px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;border-bottom:2px solid #e5e7eb;text-align:left;">Details</th>
                        <th style="padding:8px 12px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;border-bottom:2px solid #e5e7eb;text-align:right;">Duration</th>
                    </tr>
                    ' . $rows_html . '
                </table>
            </td></tr>';
        }

        $body = '
            <!-- Header -->
            <tr><td style="padding:20px 24px 0 24px;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="font-size:16px;font-weight:600;color:#111827;">Automation Run</td>
                        <td style="font-size:13px;color:#6b7280;text-align:right;">' . esc_html( $date ) . ' &middot; ' . esc_html( $time ) . '</td>
                    </tr>
                </table>
            </td></tr>

            <!-- Status Badge -->
            <tr><td style="padding:16px 24px 16px 24px;">' . $status_badge . '</td></tr>

            <!-- Content -->
            <tr><td style="padding:0 24px 20px 24px;">' . $content . '</td></tr>
        ';

        $footer = esc_html( $executed ) . ' executed &middot; ' . esc_html( $skipped ) . ' skipped &middot; <strong style="color:#111827;">' . esc_html( $total_duration ) . 's</strong> total';

        return self::wrap( $body, $footer );
    }

    /**
     * Build a rule notification email.
     *
     * @param array $notifications Array of notification items:
     *                             [ 'type' => ..., 'title' => ..., 'message' => ..., 'time' => ... ]
     *                             Optional 'message_full' (plain text, nl2br'd) or
     *                             'message_html' (trusted, pre-sanitized HTML — used
     *                             verbatim; callers MUST esc_html() any dynamic values).
     * @return string Full HTML email.
     */
    public static function rule_notifications( array $notifications ): string {
        $date  = current_time( 'D, d M Y' );
        $time  = current_time( 'H:i:s' );
        $count = count( $notifications );

        $items_html = '';
        foreach ( $notifications as $n ) {
            $type   = $n['type'] ?? 'info';
            $colors = self::TYPE_COLORS[ $type ] ?? self::TYPE_COLORS['info'];

            $items_html .= '
            <tr><td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="width:8px;vertical-align:top;padding-top:5px;">
                            <div style="width:8px;height:8px;border-radius:50%;background:' . $colors[0] . ';"></div>
                        </td>
                        <td style="padding-left:12px;">
                            <div style="font-size:13px;font-weight:600;color:#111827;margin-bottom:2px;">' . esc_html( $n['title'] ?? '' ) . '</div>
                            <div style="font-size:13px;color:#374151;line-height:1.4;">' . ( isset( $n['message_html'] ) ? $n['message_html'] : nl2br( esc_html( $n['message_full'] ?? $n['message'] ?? '' ) ) ) . '</div>
                            <div style="font-size:11px;color:#9ca3af;margin-top:4px;">' . esc_html( $n['time'] ?? '' ) . '</div>
                        </td>
                    </tr>
                </table>
            </td></tr>';
        }

        $body = '
            <!-- Header -->
            <tr><td style="padding:20px 24px 0 24px;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="font-size:16px;font-weight:600;color:#111827;">Rule Notifications</td>
                        <td style="font-size:13px;color:#6b7280;text-align:right;">' . esc_html( $date ) . ' &middot; ' . esc_html( $time ) . '</td>
                    </tr>
                </table>
            </td></tr>

            <!-- Count badge -->
            <tr><td style="padding:16px 24px;">
                <span style="display:inline-block;font-size:12px;font-weight:500;color:#0369a1;background:#f0f9ff;border:1px solid #7dd3fc;border-radius:9999px;padding:4px 12px;">' . esc_html( $count ) . ' notification' . ( $count !== 1 ? 's' : '' ) . '</span>
            </td></tr>

            <!-- Notification list -->
            <tr><td style="padding:0 24px 20px 24px;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                    ' . $items_html . '
                </table>
            </td></tr>
        ';

        $footer = '<a href="' . esc_url( site_url( '/?module=logs&action=notifications' ) ) . '" style="color:' . self::BRAND . ';text-decoration:none;">View all notifications</a>';

        return self::wrap( $body, $footer );
    }

    /**
     * Build a VT score change notification email.
     *
     * @param string $url             Short URL (no protocol).
     * @param string $score_str       e.g. "10/96 (+1)"
     * @param array  $changed_vendors ['engine' => 'malicious|suspicious|phishing', ...]
     * @param string $type            'warning' or 'success'
     * @return string Full HTML email.
     */
    public static function vt_score_change( string $url, string $score_str, array $changed_vendors, string $type = 'warning' ): string {
        $date = current_time( 'D, d M Y' );
        $time = current_time( 'H:i:s' );

        $is_worse = $type === 'warning';
        $badge    = $is_worse
            ? self::status_badge( 'error', 'Score Increased' )
            : self::status_badge( 'success', 'Score Decreased' );

        // URL + Score card.
        $card_bg     = $is_worse ? '#fef2f2' : '#f0fdf4';
        $card_border = $is_worse ? '#fca5a5' : '#86efac';
        $score_color = $is_worse ? '#dc2626' : '#16a34a';

        $card_html  = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $card_bg . ';border:1px solid ' . $card_border . ';border-radius:8px;">';
        $card_html .= '<tr><td style="padding:16px 20px;">';
        $card_html .= '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;margin-bottom:6px;">URL</div>';
        $card_html .= '<div style="font-size:15px;font-weight:600;color:#111827;margin-bottom:12px;">' . esc_html( self::defang_url( $url ) ) . '</div>';
        $card_html .= '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;margin-bottom:4px;">SCORE</div>';
        $card_html .= '<div style="font-size:22px;font-weight:700;color:' . $score_color . ';">' . esc_html( $score_str ) . '</div>';
        $card_html .= '</td></tr></table>';

        // Changed vendors table.
        $vendors_html = '';
        if ( ! empty( $changed_vendors ) ) {
            $vendors_html .= '<div style="margin-top:16px;">';
            $vendors_html .= '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;margin-bottom:8px;">New Vendor Detections</div>';
            $vendors_html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">';

            foreach ( $changed_vendors as $engine => $result ) {
                $result_lower = strtolower( $result );
                if ( str_contains( $result_lower, 'malicious' ) || str_contains( $result_lower, 'malware' ) ) {
                    $r_color = '#dc2626';
                    $r_bg    = '#fef2f2';
                } elseif ( str_contains( $result_lower, 'phish' ) ) {
                    $r_color = '#ea580c';
                    $r_bg    = '#fff7ed';
                } else {
                    $r_color = '#d97706';
                    $r_bg    = '#fffbeb';
                }

                $vendors_html .= '<tr>';
                $vendors_html .= '<td style="padding:6px 10px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#374151;">' . esc_html( $engine ) . '</td>';
                $vendors_html .= '<td style="padding:6px 10px;border-bottom:1px solid #f3f4f6;text-align:right;">';
                $vendors_html .= '<span style="display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:4px;background:' . $r_bg . ';color:' . $r_color . ';">' . esc_html( $result ) . '</span>';
                $vendors_html .= '</td></tr>';
            }

            $vendors_html .= '</table></div>';
        }

        $body = '
            <!-- Header -->
            <tr><td style="padding:20px 24px 0 24px;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="font-size:16px;font-weight:600;color:#111827;">VirusTotal Alert</td>
                        <td style="font-size:13px;color:#6b7280;text-align:right;">' . esc_html( $date ) . ' &middot; ' . esc_html( $time ) . '</td>
                    </tr>
                </table>
            </td></tr>

            <!-- Badge -->
            <tr><td style="padding:16px 24px;">' . $badge . '</td></tr>

            <!-- Score Card -->
            <tr><td style="padding:0 24px 20px 24px;">' . $card_html . $vendors_html . '</td></tr>
        ';

        $footer = '<a href="' . esc_url( site_url( '/?module=virustotal' ) ) . '" style="color:' . self::BRAND . ';text-decoration:none;">View VirusTotal Dashboard</a>';

        return self::wrap( $body, $footer );
    }

    /**
     * Balance threshold alert email (Alerts module — Phase 1).
     */
    public static function balance_alert( string $module_name, string $direction, float $threshold, float $balance, string $currency ): string {
        $date     = current_time( 'D, d M Y' );
        $time     = current_time( 'H:i:s' );
        $currency = strtoupper( trim( $currency ) ?: 'USD' );
        $fmt = function ( $v ) use ( $currency ) {
            $n = number_format( (float) $v, 2 );
            return $currency === 'USD' ? '$' . $n : $n . ' ' . $currency;
        };
        $dir_word = $direction === 'above' ? 'above' : 'below';

        // Direction-neutral amber "alert" badge (firing = attention, not good/bad).
        $badge = '<span style="display:inline-block;font-size:12px;font-weight:500;padding:4px 12px;border-radius:9999px;background:#fffbeb;color:#92400e;border:1px solid #fcd34d;line-height:18px;">'
            . '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;margin-right:6px;vertical-align:middle;"></span>'
            . '<span style="vertical-align:middle;">Balance ' . esc_html( ucfirst( $dir_word ) ) . ' Threshold</span></span>';

        $card_html  = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;">';
        $card_html .= '<tr><td style="padding:16px 20px;">';
        $card_html .= '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;margin-bottom:6px;">Integration</div>';
        $card_html .= '<div style="font-size:15px;font-weight:600;color:#111827;margin-bottom:12px;">' . esc_html( $module_name ) . '</div>';
        $card_html .= '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;margin-bottom:4px;">Current Balance</div>';
        $card_html .= '<div style="font-size:22px;font-weight:700;color:#b45309;">' . esc_html( $fmt( $balance ) ) . '</div>';
        $card_html .= '<div style="font-size:13px;color:#6b7280;margin-top:8px;">Threshold: ' . $dir_word . ' <strong style="color:#374151;">' . esc_html( $fmt( $threshold ) ) . '</strong></div>';
        $card_html .= '</td></tr></table>';

        $body = '
            <tr><td style="padding:20px 24px 0 24px;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="font-size:16px;font-weight:600;color:#111827;">Balance Alert</td>
                        <td style="font-size:13px;color:#6b7280;text-align:right;">' . esc_html( $date ) . ' &middot; ' . esc_html( $time ) . '</td>
                    </tr>
                </table>
            </td></tr>
            <tr><td style="padding:16px 24px;">' . $badge . '</td></tr>
            <tr><td style="padding:0 24px 20px 24px;">' . $card_html . '</td></tr>
        ';

        $footer = '<a href="' . esc_url( site_url( '/?module=balances' ) ) . '" style="color:' . self::BRAND . ';text-decoration:none;">View Balances</a>';

        return self::wrap( $body, $footer );
    }

    /**
     * VirusTotal quota exhausted — domain scanning paused until the daily reset.
     *
     * Sent at most once per UTC day (see SystemTasks::notify_vt_quota_exhausted),
     * alongside the bell notification.
     */
    public static function vt_quota_exhausted( int $resume_ts, int $key_count = 0 ): string {
        $date = current_time( 'D, d M Y' );
        $time = current_time( 'H:i:s' );

        $badge = '<span style="display:inline-block;font-size:12px;font-weight:500;padding:4px 12px;border-radius:9999px;background:#fffbeb;color:#92400e;border:1px solid #fcd34d;line-height:18px;">'
            . '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;margin-right:6px;vertical-align:middle;"></span>'
            . '<span style="vertical-align:middle;">Scanning Paused</span></span>';

        $resume_human = human_time_diff( time(), $resume_ts );

        $card_html  = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;">';
        $card_html .= '<tr><td style="padding:16px 20px;">';
        $card_html .= '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;margin-bottom:6px;">Integration</div>';
        $card_html .= '<div style="font-size:15px;font-weight:600;color:#111827;margin-bottom:12px;">VirusTotal</div>';
        $card_html .= '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;margin-bottom:4px;">Scanning Resumes</div>';
        $card_html .= '<div style="font-size:22px;font-weight:700;color:#b45309;">in ' . esc_html( $resume_human ) . '</div>';
        $card_html .= '<div style="font-size:13px;color:#6b7280;margin-top:8px;">Quota resets at <strong style="color:#374151;">00:00 UTC</strong></div>';
        $card_html .= '</td></tr></table>';

        // The multi-key feature implies extra keys buy extra quota. They don't —
        // VirusTotal counts the free tier against the requesting server, so saying
        // so here prevents the obvious wrong fix of pasting in more keys.
        $note = 'VirusTotal returned <strong>QuotaExceededError</strong>, so automatic domain scanning is paused '
            . 'until the quota resets. The free tier allows <strong>500 lookups per day</strong>';
        if ( $key_count > 1 ) {
            $note .= ', counted against your server rather than each key &mdash; so the '
                . esc_html( (string) $key_count ) . ' configured keys share one allowance and adding more will not raise it';
        }
        $note .= '. To scan more, reduce the scan frequency (or the number of domains), or upgrade your VirusTotal plan.';

        $body = '
            <tr><td style="padding:20px 24px 0 24px;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="font-size:16px;font-weight:600;color:#111827;">VirusTotal Quota Exhausted</td>
                        <td style="font-size:13px;color:#6b7280;text-align:right;">' . esc_html( $date ) . ' &middot; ' . esc_html( $time ) . '</td>
                    </tr>
                </table>
            </td></tr>
            <tr><td style="padding:16px 24px;">' . $badge . '</td></tr>
            <tr><td style="padding:0 24px 16px 24px;">' . $card_html . '</td></tr>
            <tr><td style="padding:0 24px 20px 24px;font-size:13px;color:#4b5563;line-height:1.6;">' . $note . '</td></tr>
        ';

        $footer = '<a href="' . esc_url( site_url( '/?module=virustotal&action=configure' ) ) . '" style="color:' . self::BRAND . ';text-decoration:none;">View VirusTotal Settings</a>';

        return self::wrap( $body, $footer );
    }

    /**
     * Report (per-campaign) alert email (Alerts module — Phase 2).
     */
    public static function report_alert( string $campaign_name, string $tf_label, array $details ): string {
        $date = current_time( 'D, d M Y' );
        $time = current_time( 'H:i:s' );

        $badge = '<span style="display:inline-block;font-size:12px;font-weight:500;padding:4px 12px;border-radius:9999px;background:#fffbeb;color:#92400e;border:1px solid #fcd34d;line-height:18px;">'
            . '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;margin-right:6px;vertical-align:middle;"></span>'
            . '<span style="vertical-align:middle;">Conditions Met</span></span>';

        $fmt = function ( $v ) { return rtrim( rtrim( number_format( (float) $v, 4 ), '0' ), '.' ); };

        $rows_html = '';
        foreach ( $details as $d ) {
            $label  = (string) ( $d['label'] ?? \Optee\Plugin\Alerts\AlertEngine::metric_label( (string) ( $d['metric'] ?? '' ) ) );
            $op     = esc_html( (string) ( $d['operator'] ?? '' ) );
            $actual = is_numeric( $d['actual'] ?? null ) ? esc_html( $fmt( $d['actual'] ) ) : 'N/A';
            $exp    = esc_html( $fmt( $d['expected'] ?? 0 ) );
            $rows_html .= '<tr>';
            $rows_html .= '<td style="padding:6px 10px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#374151;">' . esc_html( $label ) . '</td>';
            $rows_html .= '<td style="padding:6px 10px;border-bottom:1px solid #f3f4f6;text-align:right;font-size:13px;"><strong style="color:#b45309;">' . $actual . '</strong> <span style="color:#9ca3af;">(' . $op . ' ' . $exp . ')</span></td>';
            $rows_html .= '</tr>';
        }

        $card_html  = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;">';
        $card_html .= '<tr><td style="padding:16px 20px;">';
        $card_html .= '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;margin-bottom:6px;">Campaign</div>';
        $card_html .= '<div style="font-size:15px;font-weight:600;color:#111827;margin-bottom:4px;">' . esc_html( $campaign_name ) . '</div>';
        $card_html .= '<div style="font-size:12px;color:#6b7280;margin-bottom:12px;">Timeframe: ' . esc_html( $tf_label ) . '</div>';
        $card_html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">' . $rows_html . '</table>';
        $card_html .= '</td></tr></table>';

        $body = '
            <tr><td style="padding:20px 24px 0 24px;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="font-size:16px;font-weight:600;color:#111827;">Campaign Alert</td>
                        <td style="font-size:13px;color:#6b7280;text-align:right;">' . esc_html( $date ) . ' &middot; ' . esc_html( $time ) . '</td>
                    </tr>
                </table>
            </td></tr>
            <tr><td style="padding:16px 24px;">' . $badge . '</td></tr>
            <tr><td style="padding:0 24px 20px 24px;">' . $card_html . '</td></tr>
        ';

        $footer = '<a href="' . esc_url( site_url( '/?module=dashboard' ) ) . '" style="color:' . self::BRAND . ';text-decoration:none;">View campaigns</a>';

        return self::wrap( $body, $footer );
    }

    /**
     * Defang a URL for safe inclusion in emails (prevents spam filters flagging malicious URLs).
     * Replaces dots in the domain with [.]  e.g. example.com → example[.]com
     */
    public static function defang_url( string $url ): string {
        // Split on first slash to isolate domain from path.
        $slash_pos = strpos( $url, '/' );
        if ( $slash_pos === false ) {
            return str_replace( '.', '[.]', $url );
        }
        $domain = substr( $url, 0, $slash_pos );
        $path   = substr( $url, $slash_pos );
        return str_replace( '.', '[.]', $domain ) . $path;
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Wrap body content in the full email shell (doctype, body bg, logo, card, footer).
     */
    private static function wrap( string $body_rows, string $footer_html = '' ): string {
        $logo_url = esc_url( OPTEE_PLUGIN_URL . 'assets/images/optee-logo-light.svg' );

        $html  = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
        $html .= '<html xmlns="http://www.w3.org/1999/xhtml"><head>';
        $html .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0" />';
        $html .= '<title>Optee</title>';
        $html .= '<!--[if mso]><style>table{border-collapse:collapse;}</style><![endif]-->';
        $html .= '</head>';
        $html .= '<body style="margin:0;padding:0;background-color:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;-webkit-font-smoothing:antialiased;">';

        // Outer wrapper table.
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb;">';
        $html .= '<tr><td align="center" style="padding:32px 16px;">';

        // Inner container.
        $html .= '<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">';

        // Logo.
        $html .= '<tr><td style="padding-bottom:24px;">';
        $html .= '<img src="' . $logo_url . '" alt="Optee" width="120" height="40" style="display:block;height:28px;width:auto;" />';
        $html .= '</td></tr>';

        // Card.
        $html .= '<tr><td>';
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">';
        $html .= $body_rows;
        $html .= '</table>';
        $html .= '</td></tr>';

        // Footer.
        if ( $footer_html ) {
            $html .= '<tr><td style="padding:16px 0;text-align:center;font-size:12px;color:#9ca3af;">';
            $html .= $footer_html;
            $html .= '</td></tr>';
        }

        // Unsubscribe hint.
        $html .= '<tr><td style="padding:8px 0 0 0;text-align:center;font-size:11px;color:#d1d5db;">';
        $html .= 'Sent by <span style="color:#9ca3af;">Optee</span> &middot; Manage email preferences in Settings';
        $html .= '</td></tr>';

        $html .= '</table>'; // inner container
        $html .= '</td></tr></table>'; // outer wrapper
        $html .= '</body></html>';

        return $html;
    }

    /**
     * Render a colored task badge (inline HTML).
     */
    private static function task_badge( string $task ): string {
        $lower  = strtolower( $task );
        $colors = [ '#f3f4f6', '#374151' ]; // default

        foreach ( self::TASK_COLORS as $keyword => $c ) {
            if ( str_contains( $lower, $keyword ) ) {
                $colors = $c;
                break;
            }
        }

        return '<span style="display:inline-block;font-size:11px;font-weight:600;letter-spacing:0.03em;text-transform:uppercase;padding:2px 8px;border-radius:4px;background:' . $colors[0] . ';color:' . $colors[1] . ';">' . esc_html( $task ) . '</span>';
    }

    /**
     * Render a status badge (success/error).
     */
    private static function status_badge( string $type, string $label ): string {
        if ( $type === 'error' ) {
            $dot_color = '#ef4444';
            $bg        = '#fef2f2';
            $text      = '#b91c1c';
            $border    = '#fca5a5';
        } else {
            $dot_color = '#22c55e';
            $bg        = '#f0fdf4';
            $text      = '#15803d';
            $border    = '#86efac';
        }

        return '<span style="display:inline-block;font-size:12px;font-weight:500;padding:4px 12px;border-radius:9999px;background:' . $bg . ';color:' . $text . ';border:1px solid ' . $border . ';line-height:18px;">'
            . '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:' . $dot_color . ';margin-right:6px;vertical-align:middle;"></span>'
            . '<span style="vertical-align:middle;">' . esc_html( $label ) . '</span>'
            . '</span>';
    }
}
