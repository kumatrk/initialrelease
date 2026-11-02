<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Optee\Plugin\Rules\RuleEngine;

class CronHandler {

    /**
     * Initialize the cron endpoint.
     * Uses ?optee-cron=1 query param (works without rewrite rules).
     * Also keeps the /optee-cron/ rewrite as a pretty alias.
     */
    public function init(): void {
        add_action( 'init', [ $this, 'handle_cron_request_early' ], 1 );
        add_action( 'init', [ $this, 'add_rewrite_rule' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_cron_request_rewrite' ] );
    }

    /**
     * Handle cron via ?optee-cron=1 query param (no rewrite rules needed).
     */
    public function handle_cron_request_early(): void {
        if ( empty( $_GET['optee-cron'] ) ) {
            return;
        }
        $this->process_cron();
    }

    /**
     * Handle cron via /optee-cron/ rewrite rule (pretty URL alias).
     */
    public function handle_cron_request_rewrite(): void {
        if ( ! get_query_var( 'optee-cron' ) ) {
            return;
        }
        $this->process_cron();
    }

    /**
     * Register the rewrite rule for /optee-cron/ (pretty alias).
     */
    public function add_rewrite_rule(): void {
        add_rewrite_rule(
            '^optee-cron/?$',
            'index.php?optee-cron=1',
            'top'
        );
    }

    /**
     * Register the query variable.
     */
    public function add_query_vars( array $vars ): array {
        $vars[] = 'optee-cron';
        return $vars;
    }

    /**
     * Get the cron endpoint URL (query param format — works everywhere).
     *
     * @param bool $with_source  Include &source=cron param for wizard verification.
     */
    public static function get_cron_url( bool $with_source = false ): string {
        $secret = get_option( 'optee_cron_secret', '' );
        $url    = site_url( '/?optee-cron=1&token=' . $secret );
        if ( $with_source ) {
            $url .= '&source=cron';
        }
        return $url;
    }

    /**
     * Get the wget cron command string.
     *
     * @param bool $debug  Append stderr to a log file instead of /dev/null.
     */
    public static function get_cron_command( bool $debug = false ): string {
        $url = self::get_cron_url( true );
        $cmd = 'wget -q -O /dev/null "' . $url . '"';
        if ( $debug ) {
            // Log errors next to wp-config.php.
            $cmd .= ' 2>>' . ABSPATH . 'optee-cron.log';
        } else {
            $cmd .= ' >/dev/null 2>&1';
        }
        return $cmd;
    }

    /**
     * Process the cron request.
     */
    private function process_cron(): void {
        // Verify token.
        $provided_token = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
        $stored_token   = get_option( 'optee_cron_secret', '' );

        if ( empty( $stored_token ) || ! hash_equals( $stored_token, $provided_token ) ) {
            // Generic 404 — don't confirm the plugin/endpoint's existence to an
            // unauthenticated caller presenting a wrong or missing token.
            status_header( 404 );
            nocache_headers();
            exit;
        }

        // Check activation — automation requires a valid license.
        if ( ! Activation::is_activated() ) {
            wp_send_json( [
                'status'  => 'error',
                'message' => 'Plugin is not activated. Enter your license key in Settings → License.',
            ], 403 );
        }

        // Expired licenses cannot run automation.
        if ( Activation::is_expired() ) {
            wp_send_json( [
                'status'  => 'error',
                'message' => 'License has expired. Renew your license to continue using automation.',
            ], 403 );
        }

        // Detect admin user for human-readable output.
        $human_readable = is_user_logged_in() && current_user_can( 'manage_options' );

        // Self-heal stale locks before checking.
        self::maybe_clear_stale_lock();

        // Check automation lock — prevent concurrent runs.
        if ( get_transient( 'optee_automation_lock' ) !== false ) {
            $message = 'Automation is already running. Try again later.';
            if ( $human_readable ) {
                header( 'Content-Type: text/html; charset=utf-8' );
                echo self::render_html_report( [], 0, $message );
                exit;
            }
            wp_send_json( [
                'status'  => 'locked',
                'message' => $message,
            ], 409 );
        }

        // Track cron source for wizard verification.
        $source = isset( $_GET['source'] ) ? sanitize_text_field( $_GET['source'] ) : '';
        if ( $source === 'cron' ) {
            update_option( 'optee_cron_last_auto', time() );
        }

        // Set automation lock with configurable TTL.
        $lock_timeout = self::get_lock_timeout();
        set_transient( 'optee_automation_lock', time(), $lock_timeout * 60 );

        try {
            // Ensure modules are discovered (cron may fire before init priority 20).
            ModuleRegistry::get_instance()->discover();

            // Mark cron start.
            update_option( 'optee_cron_last_run', current_time( 'mysql' ) );

            $total_start   = microtime( true );
            $output_lines  = [];

            // 1. Run system tasks.
            $system_logs = SystemTasks::run();

            foreach ( $system_logs as $entry ) {
                $output_lines[] = [
                    'time'    => current_time( 'H:i:s' ),
                    'task'    => $entry['task'],
                    'detail'  => $entry['detail'],
                    'duration' => $entry['duration'] . 's',
                    'success' => $entry['success'] ?? true,
                ];
            }

            // 2. Process rules (with time budget awareness).
            $rules_start = microtime( true );
            $results     = RuleEngine::process_due_rules( $total_start );
            $rules_dur   = round( microtime( true ) - $rules_start, 1 );
            $rules_count = count( $results );

            if ( $rules_count === 0 ) {
                $output_lines[] = [
                    'time'     => current_time( 'H:i:s' ),
                    'task'     => 'RULES',
                    'detail'   => 'No rules due',
                    'duration' => "{$rules_dur}s",
                    'success'  => true,
                ];
            } else {
                foreach ( $results as $rule_id => $r ) {
                    $campaign_name = $r['campaign_name'] ?? '?';
                    $rule_name     = $r['rule_name'] ?? "Rule #{$rule_id}";
                    $status        = $r['status'] ?? 'unknown';
                    $msg           = $r['message'] ?? '';
                    $dur           = isset( $r['duration'] ) ? $r['duration'] . 's' : '—';

                    $detail = "{$campaign_name} / {$rule_name} — {$status}";
                    if ( $msg && $status !== 'no_match' ) {
                        $detail .= ": {$msg}";
                    }

                    $output_lines[] = [
                        'time'    => current_time( 'H:i:s' ),
                        'task'    => 'RULE',
                        'detail'  => $detail,
                        'duration' => $dur,
                        'success' => ! in_array( $status, [ 'error', 'failed' ], true ),
                    ];
                }
            }

            // 3. Let extension plugins run their own per-cron work (e.g. the BeMob
            // subdomain rotator). Runs inside the automation lock. Handlers must be
            // self-contained — set their own user context and catch their own errors.
            do_action( 'optee_cron_tick', $total_start );

            $total_duration = round( microtime( true ) - $total_start, 1 );

            // Mark cron complete.
            update_option( 'optee_cron_last_complete', current_time( 'mysql' ) );

            Logger::log( 'info', 'cron', "Cron completed in {$total_duration}s — " . count( $system_logs ) . ' system task(s), ' . $rules_count . ' rule(s) checked', [
                'duration'     => $total_duration,
                'system_tasks' => count( $system_logs ),
                'tasks'        => $output_lines,
                'results'      => $results,
            ] );
        } finally {
            // Always release lock.
            delete_transient( 'optee_automation_lock' );
        }

        if ( $human_readable ) {
            header( 'Content-Type: text/html; charset=utf-8' );
            echo self::render_html_report( $output_lines, $total_duration );
            exit;
        }

        wp_send_json( [
            'status'        => 'ok',
            'system_tasks'  => count( $system_logs ),
            'rules_checked' => $rules_count,
            'duration'      => $total_duration,
            'timestamp'     => current_time( 'mysql' ),
        ] );
    }

    /**
     * Render an HTML report page for logged-in admins.
     */
    private static function render_html_report( array $lines, float $total_duration, string $error = '' ): string {
        $date      = current_time( 'D, d M Y' );
        $time      = current_time( 'H:i:s' );
        $logo      = esc_url( OPTEE_PLUGIN_URL . 'assets/images/optee-logo-light.svg' );
        $has_error = ! empty( $error );

        $executed  = 0;
        foreach ( $lines as $l ) {
            if ( empty( $l['skipped'] ) ) {
                $executed++;
            }
        }

        $html  = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<title>Optee Automation</title>';
        $html .= '<style>';
        $html .= '*{margin:0;padding:0;box-sizing:border-box}';
        $html .= 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f9fafb;color:#111827;padding:32px 16px;line-height:1.5}';
        $html .= '.wrap{max-width:660px;margin:0 auto}';
        $html .= '.logo{height:28px;margin-bottom:24px}';
        $html .= '.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px 24px;margin-bottom:16px}';
        $html .= '.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}';
        $html .= '.header h1{font-size:16px;font-weight:600}';
        $html .= '.header time{font-size:13px;color:#6b7280}';
        $html .= 'table{width:100%;border-collapse:collapse;font-size:13px}';
        $html .= 'th{text-align:left;padding:8px 12px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;border-bottom:2px solid #e5e7eb}';
        $html .= 'td{padding:8px 12px;border-bottom:1px solid #f3f4f6}';
        $html .= 'tr:last-child td{border-bottom:none}';
        $html .= 'tr.skipped td{color:#9ca3af}';
        $html .= '.task{font-weight:600;font-size:11px;letter-spacing:.03em;display:inline-block;padding:2px 8px;border-radius:4px;text-transform:uppercase}';
        $html .= '.task-balance{background:#eff6ff;color:#1e40af}';
        $html .= '.task-earnings{background:#f0fdf4;color:#166534}';
        $html .= '.task-vt{background:#fef2f2;color:#991b1b}';
        $html .= '.task-rules{background:#faf5ff;color:#7e22ce}';
        $html .= '.task-default{background:#f3f4f6;color:#374151}';
        $html .= 'tr.skipped .task{opacity:.5}';
        $html .= '.dur{color:#6b7280;font-variant-numeric:tabular-nums}';
        $html .= '.detail{color:#374151}';
        $html .= 'tr.skipped .detail{color:#9ca3af}';
        $html .= '.footer{text-align:center;font-size:12px;color:#9ca3af;margin-top:12px}';
        $html .= '.footer b{color:#111827}';
        $html .= '.badge-ok{display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;color:#15803d;font-size:12px;font-weight:500;padding:4px 12px;border-radius:9999px;border:1px solid #86efac}';
        $html .= '.badge-err{display:inline-flex;align-items:center;gap:6px;background:#fef2f2;color:#b91c1c;font-size:12px;font-weight:500;padding:4px 12px;border-radius:9999px;border:1px solid #fca5a5}';
        $html .= '.dot{width:7px;height:7px;border-radius:50%;display:inline-block}';
        $html .= '.dot-green{background:#22c55e}';
        $html .= '.dot-red{background:#ef4444}';
        $html .= '.empty{padding:24px;text-align:center;color:#6b7280;font-size:14px}';
        $html .= '@media(max-width:600px){';
        $html .= 'body{padding:16px 12px}';
        $html .= '.wrap{max-width:100%}';
        $html .= '.card{padding:14px 16px;overflow:hidden}';
        $html .= '.header{flex-direction:column;align-items:flex-start;gap:4px}';
        $html .= 'thead{display:none}';
        $html .= 'table{display:block;overflow:hidden}';
        $html .= 'tbody{display:block}';
        $html .= 'tbody tr{display:block;padding:12px 0;border-bottom:1px solid #f3f4f6;overflow:hidden}';
        $html .= 'tbody tr:last-child{border-bottom:none}';
        $html .= 'td{display:block;padding:2px 0;border:none !important}';
        $html .= 'td:last-child{text-align:right;font-size:12px;color:#6b7280}';
        $html .= '}';
        $html .= '</style></head><body><div class="wrap">';
        $html .= '<img src="' . $logo . '" alt="Optee" class="logo">';
        $html .= '<div class="card"><div class="header"><h1>Automation Run</h1>';
        $html .= '<time>' . esc_html( $date ) . ' &middot; ' . esc_html( $time ) . '</time></div>';

        if ( $has_error ) {
            $html .= '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">';
            $html .= '<span class="badge-err"><span class="dot dot-red"></span>' . esc_html( $error ) . '</span></div>';
            $html .= '<div class="empty">No tasks were executed.</div>';
        } else {
            $html .= '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">';
            $html .= '<span class="badge-ok"><span class="dot dot-green"></span>Completed in ' . esc_html( $total_duration ) . 's</span></div>';
            $html .= '<table><thead><tr><th>Time</th><th>Task</th><th>Details</th><th style="text-align:right">Duration</th></tr></thead><tbody>';

            foreach ( $lines as $line ) {
                $task_lower = strtolower( $line['task'] );
                $task_class = 'task-default';
                if ( str_contains( $task_lower, 'balance' ) ) {
                    $task_class = 'task-balance';
                } elseif ( str_contains( $task_lower, 'earning' ) ) {
                    $task_class = 'task-earnings';
                } elseif ( str_contains( $task_lower, 'vt' ) || str_contains( $task_lower, 'virus' ) ) {
                    $task_class = 'task-vt';
                } elseif ( str_contains( $task_lower, 'rule' ) ) {
                    $task_class = 'task-rules';
                }

                $is_skipped = ! empty( $line['skipped'] );
                $is_error   = isset( $line['success'] ) && ! $line['success'];
                $row_class  = $is_skipped ? ' class="skipped"' : '';
                $html .= '<tr' . $row_class . '>';
                $html .= '<td class="dur">' . esc_html( $line['time'] ) . '</td>';
                $html .= '<td><span class="task ' . $task_class . '">' . esc_html( $line['task'] ) . '</span></td>';
                $detail_style = $is_error ? ' style="color:#dc2626;"' : '';
                $html .= '<td class="detail"' . $detail_style . '>' . esc_html( $line['detail'] ) . '</td>';
                $dur_display = $is_skipped ? '—' : esc_html( $line['duration'] );
                $html .= '<td class="dur" style="text-align:right">' . $dur_display . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= '</div>';
        $html .= '<div class="footer">' . esc_html( $executed ) . ' executed &middot; ' . esc_html( count( $lines ) - $executed ) . ' skipped &middot; <b>' . esc_html( $total_duration ) . 's</b> total</div>';
        $html .= '</div></body></html>';

        return $html;
    }

    /**
     * Get the lock timeout in minutes from first admin's user meta.
     */
    private static function get_lock_timeout(): int {
        $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        $admin_id = ! empty( $admins ) ? (int) $admins[0] : 1;
        $timeout = (int) get_user_meta( $admin_id, 'optee_lock_timeout', true );
        return max( 5, $timeout ?: 5 );
    }

    /**
     * Clear a stale automation lock if it has exceeded the configured timeout.
     */
    private static function maybe_clear_stale_lock(): void {
        $lock_time = get_transient( 'optee_automation_lock' );
        if ( $lock_time === false ) {
            return;
        }

        $timeout = self::get_lock_timeout();
        $elapsed = time() - (int) $lock_time;

        if ( $elapsed > ( $timeout * 60 ) ) {
            delete_transient( 'optee_automation_lock' );
            Logger::log( 'warning', 'cron', "Stale automation lock cleared after {$elapsed}s (timeout: {$timeout}min).", [
                'lock_time' => $lock_time,
                'elapsed'   => $elapsed,
            ] );
        }
    }
}
