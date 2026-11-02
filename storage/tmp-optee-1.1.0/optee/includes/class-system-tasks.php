<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SystemTasks {

    /** Threshold in seconds before a module is eligible for auto-run. */
    private const BALANCE_THRESHOLD  = 1800; // 30 min
    private const EARNINGS_THRESHOLD = 1800; // 30 min

    /**
     * Run all system tasks in order. Returns log entries.
     *
     * @return array[] Each entry: ['task'=>string, 'detail'=>string, 'duration'=>float, 'success'=>bool]
     */
    public static function run(): array {
        $start_ts = microtime( true );
        $logs = [];

        $logs[] = self::run_balance_task();
        $logs[] = self::run_manual_balance_carryforward_task();
        $logs[] = self::run_earnings_task();
        $logs[] = self::run_vt_scan_task();
        $logs[] = self::run_update_check_task();
        $logs[] = self::run_promo_check_task();
        $logs[] = self::run_license_revalidation_task();
        $logs[] = self::run_news_fetch_task();
        $logs[] = self::run_bell_notifications_task();
        $logs[] = self::run_insights_task();
        $logs[] = self::run_report_alert_task();

        do_action( 'optee_debug_event', 'cron.run', [
            'duration' => round( microtime( true ) - $start_ts, 2 ),
            'logs'     => $logs,
        ] );

        return $logs;
    }

    // ──────────────────────────────────────────
    // Report Alerts Task (Alerts module — Phase 2)
    // ──────────────────────────────────────────

    /**
     * Evaluate due per-campaign report alerts. Runs every tick but the engine
     * gates each alert to once per day at its scheduled time, so this is cheap
     * (a get_posts + skip) on ticks where nothing is due.
     */
    private static function run_report_alert_task(): array {
        $start = microtime( true );
        $fired = \Optee\Plugin\Alerts\AlertEngine::check_report_alerts();

        return [
            'task'     => 'ALERTS',
            'detail'   => $fired > 0 ? "Report alerts — {$fired} fired" : 'Report alerts — none due',
            'duration' => round( microtime( true ) - $start, 1 ),
            'success'  => true,
            'skipped'  => $fired === 0,
        ];
    }

    // ──────────────────────────────────────────
    // Balance Task
    // ──────────────────────────────────────────

    private static function run_balance_task(): array {
        $start = microtime( true );

        $candidate = self::find_stalest_module( 'balance', self::BALANCE_THRESHOLD );
        if ( ! $candidate ) {
            $total   = self::count_eligible_modules( 'balance' );
            $minutes = intval( self::BALANCE_THRESHOLD / 60 );
            return [
                'task'     => 'BALANCE',
                'detail'   => "Skipped — all {$total} integration(s) updated within last {$minutes} min",
                'duration' => 0,
                'success'  => true,
                'skipped'  => true,
            ];
        }

        $user_id = $candidate['user_id'];
        $slug    = $candidate['slug'];
        $type    = $candidate['type'];
        $module  = $candidate['module'];

        try {
            $result = self::as_user( $user_id, function () use ( $module, $type, $slug ) {
                global $wpdb;

                $creds = ModuleRegistry::get_stored_credentials( $type, $slug );
                if ( ! $creds ) {
                    return null;
                }
                $module->set_credentials( $creds );

                $balance_data = $module->get_balance();
                if ( empty( $balance_data['supported'] ) ) {
                    return null;
                }

                $balance  = floatval( $balance_data['balance'] ?? 0 );
                $currency = sanitize_text_field( $balance_data['currency'] ?? 'USD' );
                $today    = current_time( 'Y-m-d' );
                $table    = $wpdb->prefix . 'optee_balances';

                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$table} (user_id, module_type, module_slug, balance_date, balance, currency)
                     VALUES (%d, %s, %s, %s, %f, %s)
                     ON DUPLICATE KEY UPDATE balance = %f, currency = %s",
                    get_current_user_id(), $type, $slug, $today, $balance, $currency,
                    $balance, $currency
                ) );

                return [
                    'balance'  => $balance,
                    'currency' => $currency,
                ];
            } );

            self::set_last_auto_run( 'balance', $user_id, $slug );

            if ( ! $result ) {
                $name     = $module->get_name();
                $duration = round( microtime( true ) - $start, 1 );
                return [
                    'task'     => 'BALANCE',
                    'detail'   => "{$name} (user #{$user_id}) — Skipped (no credentials or unsupported)",
                    'duration' => $duration,
                    'success'  => true,
                    'skipped'  => true,
                ];
            }

            $duration = round( microtime( true ) - $start, 1 );
            $name     = $module->get_name();

            // Alerts module (Phase 1): evaluate balance threshold alerts against the
            // freshly-updated balance. Fires at most once per calendar day per alert.
            \Optee\Plugin\Alerts\AlertEngine::check_balance(
                $user_id, $type, $slug, $name,
                (float) $result['balance'], (string) $result['currency']
            );

            return [
                'task'     => 'BALANCE',
                'detail'   => "{$name} (user #{$user_id}) — \${$result['balance']} {$result['currency']}",
                'duration' => $duration,
                'success'  => true,
            ];
        } catch ( \Throwable $e ) {
            // Still update timestamp to prevent retry storm.
            self::set_last_auto_run( 'balance', $user_id, $slug );
            $duration = round( microtime( true ) - $start, 1 );

            Logger::log( 'error', 'cron', "Auto-balance failed for {$slug} (user #{$user_id}): " . $e->getMessage() );

            return [
                'task'     => 'BALANCE',
                'detail'   => "{$slug} (user #{$user_id}) — ERROR: " . $e->getMessage(),
                'duration' => $duration,
                'success'  => false,
            ];
        }
    }

    // ──────────────────────────────────────────
    // Manual Balance Carry-Forward Task
    // ──────────────────────────────────────────

    /**
     * Manually-tracked platforms have no API, so the auto-balance task never updates them.
     * To keep their column from dropping to $0 on a new day, carry the last known balance
     * forward to today. Idempotent + cheap, so it runs every tick.
     */
    private static function run_manual_balance_carryforward_task(): array {
        $start = microtime( true );

        $admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
        $total = 0;
        foreach ( $admin_ids as $uid ) {
            $total += self::carry_forward_manual_balances( intval( $uid ) );
        }

        return [
            'task'     => 'BALANCE',
            'detail'   => $total > 0 ? "Carried forward {$total} manual balance(s) to today" : 'No manual balances to carry forward',
            'duration' => round( microtime( true ) - $start, 1 ),
            'success'  => true,
            'skipped'  => $total === 0,
        ];
    }

    /**
     * For each manually-tracked platform, copy the most recent known balance to today when
     * today has no entry yet OR today's entry is 0 (for manual modules a 0 means "not updated
     * today", not a real zero balance). Looks back past gaps to the last non-zero balance.
     *
     * All queries scope by the explicit $user_id, so no WP user-context switch is needed.
     *
     * @return int Number of platforms carried forward.
     */
    public static function carry_forward_manual_balances( int $user_id ): int {
        global $wpdb;

        $manual_modules = get_user_meta( $user_id, 'optee_manual_balance_modules', true ) ?: [];
        if ( ! is_array( $manual_modules ) || empty( $manual_modules ) ) {
            return 0;
        }

        $table = $wpdb->prefix . 'optee_balances';
        $today = current_time( 'Y-m-d' );
        $count = 0;

        foreach ( $manual_modules as $mm ) {
            $slug = sanitize_key( $mm['slug'] ?? '' );
            $type = sanitize_key( $mm['type'] ?? '' );
            if ( $slug === '' || $type === '' ) {
                continue;
            }

            // Today's entry (NULL when no row exists at all).
            $today_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT balance, is_manual FROM {$table}
                 WHERE user_id = %d AND module_slug = %s AND module_type = %s AND balance_date = %s",
                $user_id, $slug, $type, $today
            ), ARRAY_A );

            // Leave today alone if it already has a real (non-zero) balance OR the user
            // explicitly edited it today (is_manual = 1) — including an intentional 0.
            if ( $today_row !== null
                && ( floatval( $today_row['balance'] ) != 0.0 || intval( $today_row['is_manual'] ) === 1 ) ) {
                continue;
            }

            // Most recent prior meaningful balance — non-zero, OR a manual entry (so an
            // intentional 0 set on a previous day carries forward as 0). Handles gaps too.
            $prev = $wpdb->get_row( $wpdb->prepare(
                "SELECT balance, currency FROM {$table}
                 WHERE user_id = %d AND module_slug = %s AND module_type = %s
                   AND balance_date < %s AND ( balance > 0 OR is_manual = 1 )
                 ORDER BY balance_date DESC
                 LIMIT 1",
                $user_id, $slug, $type, $today
            ), ARRAY_A );

            if ( ! $prev ) {
                continue;
            }

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$table} (user_id, module_type, module_slug, balance_date, balance, currency)
                 VALUES (%d, %s, %s, %s, %f, %s)
                 ON DUPLICATE KEY UPDATE balance = VALUES(balance), currency = VALUES(currency)",
                $user_id, $type, $slug, $today, floatval( $prev['balance'] ), $prev['currency'] ?: 'USD'
            ) );
            $count++;
        }

        return $count;
    }

    // ──────────────────────────────────────────
    // Earnings Task
    // ──────────────────────────────────────────

    private static function run_earnings_task(): array {
        $start = microtime( true );

        $candidate = self::find_stalest_module( 'earnings', self::EARNINGS_THRESHOLD );
        if ( ! $candidate ) {
            $total   = self::count_eligible_modules( 'earnings' );
            $minutes = intval( self::EARNINGS_THRESHOLD / 60 );
            return [
                'task'     => 'EARNINGS',
                'detail'   => "Skipped — all {$total} integration(s) updated within last {$minutes} min",
                'duration' => 0,
                'success'  => true,
                'skipped'  => true,
            ];
        }

        $user_id = $candidate['user_id'];
        $slug    = $candidate['slug'];
        $type    = $candidate['type'];
        $module  = $candidate['module'];

        try {
            $days = self::as_user( $user_id, function () use ( $module, $type, $slug ) {
                global $wpdb;

                $creds = ModuleRegistry::get_stored_credentials( $type, $slug );
                if ( ! $creds ) {
                    return null;
                }
                $module->set_credentials( $creds );

                if ( ! method_exists( $module, 'get_account_earnings' ) ) {
                    return null;
                }

                $date_from = wp_date( 'Y-m-d', strtotime( '-29 days' ) );
                $date_to   = current_time( 'Y-m-d' );
                $result    = $module->get_account_earnings( $date_from, $date_to );

                if ( empty( $result['supported'] ) || empty( $result['data'] ) ) {
                    return null;
                }

                $table = $wpdb->prefix . 'optee_earnings';
                foreach ( $result['data'] as $row ) {
                    $date   = sanitize_text_field( $row['date'] ?? '' );
                    $amount = floatval( $row['amount'] ?? 0 );
                    if ( ! $date ) {
                        continue;
                    }

                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$table} (user_id, module_type, module_slug, earnings_date, amount, currency)
                         VALUES (%d, %s, %s, %s, %f, %s)
                         ON DUPLICATE KEY UPDATE amount = %f",
                        get_current_user_id(), $type, $slug, $date, $amount, 'USD',
                        $amount
                    ) );
                }

                return count( $result['data'] );
            } );

            self::set_last_auto_run( 'earnings', $user_id, $slug );

            if ( ! $days ) {
                $name     = $module->get_name();
                $duration = round( microtime( true ) - $start, 1 );
                return [
                    'task'     => 'EARNINGS',
                    'detail'   => "{$name} (user #{$user_id}) — Skipped (no credentials or unsupported)",
                    'duration' => $duration,
                    'success'  => true,
                    'skipped'  => true,
                ];
            }

            $duration = round( microtime( true ) - $start, 1 );
            $name     = $module->get_name();

            return [
                'task'     => 'EARNINGS',
                'detail'   => "{$name} (user #{$user_id}) — {$days} days",
                'duration' => $duration,
                'success'  => true,
            ];
        } catch ( \Throwable $e ) {
            self::set_last_auto_run( 'earnings', $user_id, $slug );
            $duration = round( microtime( true ) - $start, 1 );

            Logger::log( 'error', 'cron', "Auto-earnings failed for {$slug} (user #{$user_id}): " . $e->getMessage() );

            return [
                'task'     => 'EARNINGS',
                'detail'   => "{$slug} (user #{$user_id}) — ERROR: " . $e->getMessage(),
                'duration' => $duration,
                'success'  => false,
            ];
        }
    }

    // ──────────────────────────────────────────
    // VT Scan Task
    // ──────────────────────────────────────────

    /**
     * Max VirusTotal API calls per cron run. The free tier allows ~4 requests per
     * minute, so this doubles as the rate-limit guard — raise it only on a paid key.
     */
    private const VT_BATCH_MAX_CALLS = 4;

    /** Wall-clock ceiling for the whole batch, so a slow VT never stalls cron. */
    private const VT_BATCH_BUDGET_SECONDS = 20;

    /**
     * How long to pause the task after a transient VirusTotal 429
     * (TooManyRequestsError — the ~4/min burst limit, which clears in seconds).
     */
    private const VT_QUOTA_BACKOFF_SECONDS = 900;

    /**
     * Ceiling for a QuotaExceededError backoff. That error does NOT clear until
     * VirusTotal's daily reset, so retrying every 15 min just burns requests and
     * re-arms the backoff forever; we sleep until the next UTC midnight instead.
     */
    private const VT_QUOTA_DAILY_BACKOFF_MAX = 86400;

    /** Rolling per-day API usage, for the usage chart on the VirusTotal page. */
    private const VT_CALL_COUNTS_META    = 'optee_vt_call_counts';
    private const VT_QUOTA_HISTORY_META  = 'optee_vt_quota_history';
    private const VT_HISTORY_DAYS        = 30;

    /** Set by scan_submit_vt()/scan_read_vt() when VirusTotal answers HTTP 429. */
    private static bool $vt_quota_hit = false;

    /**
     * VirusTotal's error.code from that 429 — the only field that distinguishes a
     * transient burst limit from a spent allowance. Both share the message
     * "Quota exceeded"/"Too many requests", so the code is what we branch on.
     */
    private static string $vt_quota_code = '';

    /** User whose scan tripped the 429, so the bell notification reaches them. */
    private static int $vt_quota_user_id = 0;

    /**
     * Process a batch of VT work per cron run.
     *
     * Previously this handled exactly one URL per run, so with N URLs a full sweep
     * took >= 2N cron runs (one to submit, one to read) — which made the user's
     * "check every X min" setting unreachable and left every URL showing the same
     * stale timestamp. Both phases are single non-blocking calls, so several fit in
     * one run without reintroducing the sleep/poll stall this replaced.
     *
     * Bounded three ways: API calls per run, wall-clock budget, and a backoff after
     * a 429. Reads are drained before new submissions so results land promptly.
     */
    /**
     * Record a VirusTotal 429, keeping the error.code and the affected user.
     *
     * VirusTotal returns 429 for two unrelated conditions that look identical in
     * the message field, so the code is the only way to tell them apart:
     *   TooManyRequestsError → the ~4/min burst limit; clears in seconds.
     *   QuotaExceededError   → daily/monthly allowance spent; will NOT clear
     *                          until VirusTotal's reset.
     */
    private static function flag_vt_quota_hit( int $user_id, $response ): void {
        self::$vt_quota_hit     = true;
        self::$vt_quota_user_id = $user_id;

        $body                 = json_decode( wp_remote_retrieve_body( $response ), true );
        self::$vt_quota_code  = (string) ( $body['error']['code'] ?? '' );
    }

    /** True when VirusTotal said the allowance is spent, not merely too fast. */
    private static function vt_quota_is_exhausted(): bool {
        return self::$vt_quota_code === 'QuotaExceededError';
    }

    /**
     * Count one VirusTotal HTTP request against today's tally.
     *
     * Every scan costs a submit plus at least one read, so the scan table cannot be
     * used as a proxy — it holds one row per URL per day and understates real usage
     * several-fold. This counts actual requests.
     *
     * Bucketed by UTC date on purpose: VirusTotal's daily allowance resets at 00:00
     * UTC, so a site-time bucket would split one allowance across two bars and make
     * the chart disagree with the API's own "used" figure.
     *
     * @param string $kind 'submit', 'read', or 'aux' — kept separately so the split
     *                     between resubmissions, result reads and out-of-band UI
     *                     traffic stays visible.
     */
    private static function vt_count_call( int $user_id, string $kind = 'read', string $route = 'direct' ): void {
        if ( ! $user_id ) {
            return;
        }

        $counts = get_user_meta( $user_id, self::VT_CALL_COUNTS_META, true );
        $counts = is_array( $counts ) ? $counts : [];

        $today = gmdate( 'Y-m-d' );
        if ( ! isset( $counts[ $today ] ) || ! is_array( $counts[ $today ] ) ) {
            $counts[ $today ] = [ 'submit' => 0, 'read' => 0, 'aux' => 0, 'direct' => 0, 'proxy' => 0 ];
        }
        $key = in_array( $kind, [ 'submit', 'read', 'aux' ], true ) ? $kind : 'read';
        $counts[ $today ][ $key ] = (int) ( $counts[ $today ][ $key ] ?? 0 ) + 1;

        // Counted on a second axis by egress route. The allowance is enforced per
        // source IP, so this is the split that decides when scanning stops —
        // whereas submit/read only describes what the calls were for.
        $route_key = ( $route === 'proxy' ) ? 'proxy' : 'direct';
        $counts[ $today ][ $route_key ] = (int) ( $counts[ $today ][ $route_key ] ?? 0 ) + 1;

        // Keep a bounded window — this meta is read whole on every VT page load.
        if ( count( $counts ) > self::VT_HISTORY_DAYS ) {
            krsort( $counts );
            $counts = array_slice( $counts, 0, self::VT_HISTORY_DAYS, true );
        }

        update_user_meta( $user_id, self::VT_CALL_COUNTS_META, $counts );
    }

    /**
     * Count a VirusTotal request made by the module rather than the scan loop.
     *
     * Fired by optee-antifraud-virustotal as `optee_vt_api_call`. Those requests
     * spend the same allowance as a scan — a configure-page load costs one per
     * configured key — but they run in a page/AJAX context, so they never passed
     * through the counters inside run_vt_scan_task(). The result was a usage chart
     * that under-reported this install and attributed the difference to other
     * traffic sharing the server's IP.
     *
     * Bucketed as 'aux' so genuine scan volume stays legible on its own.
     *
     * @param string $kind  Free-form label from the module ('keyinfo', 'probe', ...);
     *                      only 'submit'/'read' keep their own bucket.
     * @param string $route 'direct' or 'proxy' — which egress carried the request.
     */
    public static function count_module_vt_call( string $kind = 'aux', string $route = 'direct' ): void {
        $bucket = in_array( $kind, [ 'submit', 'read' ], true ) ? $kind : 'aux';
        self::vt_count_call( get_current_user_id(), $bucket, $route );
    }

    /**
     * Make a VirusTotal request through the module so egress routing, per-route
     * counting and logging all happen in one place.
     *
     * Falls back to a direct call when the installed module plugin predates
     * routing — core and the module update independently, and a scan must not
     * fail just because one of them is older.
     *
     * @return array{response: array|\WP_Error, key_index: int, route: string}
     */
    private static function vt_call( $module, int $user_id, string $method, string $url, array $args = [], ?int $key_index = null ): array {
        if ( method_exists( $module, 'vt_request' ) ) {
            // Counting happens inside, via the optee_vt_api_call listener — do not
            // also count here or every routed call is tallied twice.
            return $module->vt_request( $method, $url, $args, $key_index );
        }

        self::vt_count_call( $user_id, strtoupper( $method ) === 'POST' ? 'submit' : 'read' );

        $args['headers'] = array_merge( [
            'x-apikey' => $module->get_api_key(),
            'Accept'   => 'application/json',
        ], $args['headers'] ?? [] );
        $args['timeout'] = $args['timeout'] ?? 30;
        $args['method']  = $method;

        return [
            'response'  => wp_remote_request( $url, $args ),
            'key_index' => 0,
            'route'     => 'direct',
        ];
    }

    /**
     * Snapshot VirusTotal's own daily quota (used/allowed) for the usage chart.
     *
     * This is the authoritative series — our own counter only ever sees requests
     * this install made, so plotting both makes contention for a shared allowance
     * visible instead of leaving it to be misread as a bug.
     *
     * COST. There is no cheap way to read a quota: GET /users/{id} is the only
     * endpoint that exposes it, and get_keys_info() calls it once PER KEY. Refreshing
     * hourly therefore cost 3 keys x 24 h = 72 requests a day out of a 500/day
     * allowance — 14% of the budget spent describing the budget. Every key has to be
     * read on each pass (see the summing note below), so the saving has to come from
     * frequency instead: SNAPSHOT_INTERVAL of 3h puts it at 3 x 8 = 24 a day, and the
     * bar still tracks its high-water mark closely enough to be worth plotting.
     */
    private const VT_SNAPSHOT_INTERVAL = 3 * HOUR_IN_SECONDS;

    private static function vt_snapshot_quota( int $user_id ): void {
        if ( ! $user_id ) {
            return;
        }

        $today   = gmdate( 'Y-m-d' );
        $history = get_user_meta( $user_id, self::VT_QUOTA_HISTORY_META, true );
        $history = is_array( $history ) ? $history : [];

        // Re-snapshot through the day so the bar tracks the real high-water mark,
        // but not more often than VT_SNAPSHOT_INTERVAL — each pass costs one request
        // per configured key.
        if ( isset( $history[ $today ]['at'] ) && ( time() - (int) $history[ $today ]['at'] ) < self::VT_SNAPSHOT_INTERVAL ) {
            return;
        }

        $module = ModuleRegistry::get_instance()->get_module( 'virustotal' );
        if ( ! $module || ! method_exists( $module, 'get_keys_info' ) ) {
            return;
        }
        $creds = ModuleRegistry::get_stored_credentials( 'virustotal', 'virustotal' );
        if ( ! $creds ) {
            return;
        }

        $keys = array_values( array_filter( array_map( 'trim', explode( ';', $creds['api_key'] ?? '' ) ) ) );
        if ( ! $keys ) {
            return;
        }

        $module->set_credentials( $creds );

        // get_keys_info() flattens the quota object to a display list keyed by label.
        $per_key = [];
        $allowed = 0;
        foreach ( (array) $module->get_keys_info() as $info ) {
            foreach ( (array) ( $info['quotas'] ?? [] ) as $q ) {
                if ( ( $q['label'] ?? '' ) !== 'Daily' ) {
                    continue;
                }
                $per_key[ (int) ( $info['index'] ?? count( $per_key ) + 1 ) ] = (int) ( $q['used'] ?? 0 );

                // The allowance is NOT summed. VirusTotal enforces the free-tier
                // daily limit per source IP, not per key (proven 2026-07-30: three
                // keys all returned QuotaExceededError from this server while the
                // same keys worked from elsewhere). Adding the keys' allowances
                // would draw a 1500/day ceiling that does not exist, so the ceiling
                // is one key's allowance — the limit that actually binds.
                $allowed = max( $allowed, (int) ( $q['allowed'] ?? 0 ) );
            }
        }

        if ( $allowed <= 0 || ! $per_key ) {
            return; // key invalid or quota unreadable — don't store a misleading zero
        }

        // Spend IS summed while the allowance is not, and the asymmetry is real
        // rather than a bug — the two numbers are metered by different things.
        //
        // Measured 2026-07-31 with debug/vt-quota-probe.php: the three keys read
        // 160 / 161 / 142 daily from a machine that had not spent any of it. A
        // counter shared across the keys would answer every caller identically;
        // these diverge and follow the key, so `used` is per KEY. Enforcement is
        // separate: all three returned QuotaExceededError from the live server at
        // a summed ~450 while each sat at ~150 of its own 500, so the free daily
        // ceiling additionally binds per source IP (proven 2026-07-30).
        //
        // Summing therefore estimates what this install pushed through its IP,
        // which is the quantity that actually runs out, and one key's allowance is
        // the ceiling it runs out against. Adding the allowances would draw a
        // 1500/day line that does not exist.
        //
        // Known drift: a key also used from another machine carries that spend in
        // its counter without having cost this IP anything, so the total reads
        // high. The per-key figures are stored to keep that diagnosable.
        $used = array_sum( $per_key );

        $history[ $today ] = [
            'used'    => $used,
            'allowed' => $allowed,
            'at'      => time(),
            'per_key' => $per_key,
        ];

        if ( count( $history ) > self::VT_HISTORY_DAYS ) {
            krsort( $history );
            $history = array_slice( $history, 0, self::VT_HISTORY_DAYS, true );
        }

        update_user_meta( $user_id, self::VT_QUOTA_HISTORY_META, $history );
    }

    /**
     * Merged per-day series for the VirusTotal usage chart.
     *
     * @return array<int, array{date:string,submit:int,read:int,aux:int,calls:int,quota_used:int,quota_allowed:int,per_key:array}>
     */
    public static function vt_usage_history( int $user_id, int $days = 14 ): array {
        $counts  = get_user_meta( $user_id, self::VT_CALL_COUNTS_META, true );
        $counts  = is_array( $counts ) ? $counts : [];
        $history = get_user_meta( $user_id, self::VT_QUOTA_HISTORY_META, true );
        $history = is_array( $history ) ? $history : [];

        $days = max( 1, min( self::VT_HISTORY_DAYS, $days ) );
        $out  = [];

        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $date   = gmdate( 'Y-m-d', strtotime( "-{$i} days", strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00 UTC' ) ) );
            $submit = (int) ( $counts[ $date ]['submit'] ?? 0 );
            $read   = (int) ( $counts[ $date ]['read'] ?? 0 );

            // Out-of-band module traffic (key badges, connection tests, the quota
            // snapshot itself) spends the same allowance, so it belongs in the
            // plotted total even though it is not scan volume.
            $aux = (int) ( $counts[ $date ]['aux'] ?? 0 );

            $out[] = [
                'date'          => $date,
                'submit'        => $submit,
                'read'          => $read,
                'aux'           => $aux,
                'calls'         => $submit + $read + $aux,
                // Egress split. This is the axis that decides when scanning stops,
                // because the allowance is enforced per source IP.
                'direct'        => (int) ( $counts[ $date ]['direct'] ?? 0 ),
                'proxy'         => (int) ( $counts[ $date ]['proxy'] ?? 0 ),
                'quota_used'    => (int) ( $history[ $date ]['used'] ?? 0 ),
                'quota_allowed' => (int) ( $history[ $date ]['allowed'] ?? 0 ),
                'per_key'       => (array) ( $history[ $date ]['per_key'] ?? [] ),
            ];
        }

        return $out;
    }

    /**
     * How long to sleep after a 429. A burst limit clears almost immediately, but
     * a spent allowance does not come back until VirusTotal's daily reset (UTC
     * midnight) — retrying every 15 min in that case just burns requests and
     * re-arms the backoff indefinitely.
     */
    private static function vt_backoff_seconds(): int {
        if ( ! self::vt_quota_is_exhausted() ) {
            return self::VT_QUOTA_BACKOFF_SECONDS;
        }

        // Seconds remaining until the next UTC midnight, clamped so a clock skew
        // can never park the scanner for longer than a day.
        $until = strtotime( 'tomorrow midnight UTC' ) - time();
        return max( self::VT_QUOTA_BACKOFF_SECONDS, min( $until, self::VT_QUOTA_DAILY_BACKOFF_MAX ) );
    }

    /**
     * Bell-notify the affected user that VirusTotal scanning has stalled.
     *
     * Fires at most once per UTC day per user: the scanner re-checks on every
     * cron run, and without the guard an exhausted quota would post a
     * notification every few minutes for the rest of the day.
     */
    private static function notify_vt_quota_exhausted(): void {
        $user_id = self::$vt_quota_user_id;
        if ( ! $user_id ) {
            return;
        }

        $exhausted = self::vt_quota_is_exhausted();
        $today     = gmdate( 'Y-m-d' );
        $flag      = 'optee_vt_quota_notified';

        // Burst limits are self-healing and not worth a notification at all.
        if ( ! $exhausted ) {
            return;
        }

        if ( get_user_meta( $user_id, $flag, true ) === $today ) {
            return;
        }
        update_user_meta( $user_id, $flag, $today );

        $resume = strtotime( 'tomorrow midnight UTC' );

        Notifications::add( $user_id, [
            'type'         => 'warning',
            'title'        => 'VirusTotal quota exhausted',
            'message'      => 'Domain scanning is paused until the quota resets.',
            'message_full' => 'VirusTotal returned QuotaExceededError, so automatic domain scanning '
                . 'has been paused until the daily quota resets (about '
                . human_time_diff( time(), $resume ) . ' from now, at 00:00 UTC). '
                . 'The free VirusTotal tier allows 500 lookups per day and is counted against your '
                . 'server, so adding more API keys does not raise the limit — reduce the scan '
                . 'frequency or upgrade the VirusTotal plan to scan more domains.',
            'time'         => current_time( 'mysql' ),
        ] );

        self::send_vt_quota_email( $user_id, $resume );

        Logger::log( 'warning', 'cron', 'VirusTotal quota exhausted — scanning paused until reset', [
            'user_id'    => $user_id,
            'error_code' => self::$vt_quota_code,
            'resume_at'  => gmdate( 'Y-m-d H:i:s', $resume ) . ' UTC',
        ] );
    }

    /** Email counterpart of the bell notification. Same once-per-UTC-day guard. */
    private static function send_vt_quota_email( int $user_id, int $resume_ts ): void {
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return;
        }

        // Report how many keys are configured so the email can say plainly that
        // they share one server-wide allowance.
        $keys = 0;
        $creds = self::as_user( $user_id, function () {
            return ModuleRegistry::get_stored_credentials( 'virustotal', 'virustotal' );
        } );
        if ( is_array( $creds ) && ! empty( $creds['api_key'] ) ) {
            $keys = count( array_filter( array_map( 'trim', explode( ';', (string) $creds['api_key'] ) ) ) );
        }

        $subject = 'Optee — VirusTotal quota exhausted, scanning paused';
        $html    = EmailTemplate::vt_quota_exhausted( $resume_ts, $keys );

        // Capture any wp_mail_failed error WordPress throws when delivery fails —
        // this fires once a day from cron, so a silent failure would otherwise be
        // indistinguishable from "the quota never ran out".
        $mail_error = null;
        $capture = function ( $wp_error ) use ( &$mail_error ) {
            $mail_error = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : (string) $wp_error;
        };
        add_action( 'wp_mail_failed', $capture );

        $html_type = function () { return 'text/html'; };
        add_filter( 'wp_mail_content_type', $html_type );
        $sent = wp_mail( $user->user_email, $subject, $html );
        remove_filter( 'wp_mail_content_type', $html_type );
        remove_action( 'wp_mail_failed', $capture );

        if ( ! $sent ) {
            Logger::log( 'error', 'cron', 'VirusTotal quota email failed to send', [
                'user_id'   => $user_id,
                'recipient' => $user->user_email,
                'error'     => $mail_error ?: 'wp_mail() returned false with no error',
            ] );
        }

        do_action( 'optee_debug_event', 'vt.quota.email', [
            'user_id'   => $user_id,
            'recipient' => $user->user_email,
            'sent'      => (bool) $sent,
            'error'     => $mail_error,
        ] );
    }

    private static function run_vt_scan_task(): array {
        $start = microtime( true );

        // Respect a backoff from a previous run — hammering a rate-limited key just
        // burns cron runs and keeps everything stale.
        $backoff_until = intval( get_option( 'optee_vt_quota_backoff_until', 0 ) );
        if ( $backoff_until > time() ) {
            $mins = max( 1, (int) round( ( $backoff_until - time() ) / 60 ) );
            return [
                'task'     => 'VT SCAN',
                'detail'   => "Skipped — VirusTotal rate/quota limit hit, resuming in ~{$mins} min",
                'duration' => 0,
                'success'  => true,
                'skipped'  => true,
            ];
        }

        self::$vt_quota_hit = false;

        $calls     = 0;
        $stored    = 0;
        $still     = 0;
        $gave_up   = 0;
        $submitted = 0;
        $drained   = false;
        $errors    = [];

        // "user_id:index" handled in this run. Without it a read that comes back
        // "still processing" leaves its pending marker in place and the next loop
        // iteration would pick the very same URL, burning the whole batch on one.
        $skip = [];

        // Users whose URLs were touched this run — quota is snapshotted for them
        // afterwards, so the usage chart has an authoritative series to plot.
        $users_seen = [];

        while (
            $calls < self::VT_BATCH_MAX_CALLS
            && ( microtime( true ) - $start ) < self::VT_BATCH_BUDGET_SECONDS
        ) {
            // ── Phase 1: drain a previously-submitted analysis that may now be ready.
            $pending = self::find_pending_vt_url( $skip );
            if ( $pending ) {
                $user_id   = $pending['user_id'];
                $url       = $pending['url'];
                $url_index = $pending['index'];

                $skip[ $user_id . ':' . $url_index ] = true;
                $users_seen[ $user_id ] = true;
                $calls++;

                try {
                    $result = self::as_user( $user_id, function () use ( $user_id, $url, $url_index ) {
                        return self::scan_read_vt( $user_id, $url, $url_index );
                    } );

                    if ( ! empty( $result['gave_up'] ) ) {
                        $gave_up++;
                    } elseif ( ! empty( $result['pending'] ) ) {
                        $still++;
                    } else {
                        $stored++;
                    }
                } catch ( \Throwable $e ) {
                    $errors[] = 'read ' . $url . ': ' . $e->getMessage();
                    Logger::log( 'error', 'cron', "Auto-VT read failed for {$url} (user #{$user_id}): " . $e->getMessage() );
                }

                if ( self::$vt_quota_hit ) {
                    break;
                }
                continue;
            }

            // ── Phase 2: submit the stalest eligible URL for a fresh analysis.
            $candidate = self::find_stalest_vt_url( $skip );
            if ( ! $candidate ) {
                $drained = true;
                break;
            }

            $user_id   = $candidate['user_id'];
            $url       = $candidate['url'];
            $url_index = $candidate['index'];

            $skip[ $user_id . ':' . $url_index ] = true;
            $users_seen[ $user_id ] = true;
            $calls++;

            try {
                self::as_user( $user_id, function () use ( $user_id, $url, $url_index ) {
                    self::scan_submit_vt( $user_id, $url, $url_index );
                } );
                $submitted++;

                // Gate re-submission by the user's interval; the result is read by
                // Phase 1, on this run or a later one.
                $url_hash = substr( hash( 'sha256', $url ), 0, 16 );
                self::set_last_auto_run( 'vt', $user_id, $url_hash );
            } catch ( \Throwable $e ) {
                $errors[] = 'submit ' . $url . ': ' . $e->getMessage();
                Logger::log( 'error', 'cron', "Auto-VT submit failed for {$url} (user #{$user_id}): " . $e->getMessage() );
            }

            if ( self::$vt_quota_hit ) {
                break;
            }
        }

        if ( self::$vt_quota_hit ) {
            update_option( 'optee_vt_quota_backoff_until', time() + self::vt_backoff_seconds(), false );
            self::notify_vt_quota_exhausted();
        }

        // Record VirusTotal's own view of today's allowance. Rate-limited to once an
        // hour per user inside the helper, and all but the day's first reading query
        // a single key, so this costs ~1 request an hour rather than one per key.
        // It is the only series that sees usage from anything else sharing this IP.
        // Runs inside as_user() so the requests it makes are attributed to the right
        // user by the optee_vt_api_call counter.
        foreach ( array_keys( $users_seen ) as $seen_user_id ) {
            try {
                self::as_user( (int) $seen_user_id, function () use ( $seen_user_id ) {
                    self::vt_snapshot_quota( (int) $seen_user_id );
                } );
            } catch ( \Throwable $e ) {
                // Never let a bookkeeping failure affect the scan task's result.
                Logger::log( 'warning', 'cron', 'VT quota snapshot failed: ' . $e->getMessage() );
            }
        }

        $duration = round( microtime( true ) - $start, 1 );

        // Nothing was eligible at all — everything is inside its interval.
        if ( $calls === 0 ) {
            $total = self::count_active_vt_urls();
            return [
                'task'     => 'VT SCAN',
                'detail'   => "Skipped — all {$total} domain(s) scanned within configured interval",
                'duration' => $duration,
                'success'  => true,
                'skipped'  => true,
            ];
        }

        $parts = [];
        if ( $stored ) {
            $parts[] = "{$stored} result(s) stored";
        }
        if ( $still ) {
            $parts[] = "{$still} still processing";
        }
        if ( $gave_up ) {
            $parts[] = "{$gave_up} gave up";
        }
        if ( $submitted ) {
            $parts[] = "{$submitted} submitted";
        }
        if ( $drained ) {
            $parts[] = 'queue drained';
        }
        if ( self::$vt_quota_hit ) {
            $parts[] = 'VT rate limit hit — backing off';
        }

        $detail = implode( ', ', $parts ) . " ({$calls} API call(s))";
        if ( $errors ) {
            $detail .= ' — ' . count( $errors ) . ' error(s): ' . implode( '; ', array_slice( $errors, 0, 2 ) );
        }

        return [
            'task'     => 'VT SCAN',
            'detail'   => $detail,
            'duration' => $duration,
            'success'  => empty( $errors ),
        ];
    }

    /**
     * Scan a URL via VirusTotal API and store the results. Shared by AJAX and cron.
     *
     * @param int    $user_id  Owner user ID.
     * @param string $url      The URL to scan.
     * @param int    $url_index Index in the user's VT URL list (for updating meta).
     * @return array ['malicious' => int, 'total' => int, 'stats' => array]
     */
    public static function scan_and_store_vt( int $user_id, string $url, int $url_index ): array {
        global $wpdb;

        do_action( 'optee_debug_event', 'vt.scan.start', [
            'user_id'   => $user_id,
            'url'       => $url,
            'url_index' => $url_index,
        ] );

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( 'virustotal' );
        if ( ! $module ) {
            throw new \RuntimeException( 'VirusTotal module not found.' );
        }

        $creds = ModuleRegistry::get_stored_credentials( 'virustotal', 'virustotal' );
        if ( ! $creds ) {
            throw new \RuntimeException( 'VirusTotal not configured.' );
        }
        $module->set_credentials( $creds );

        $url_id = rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );

        // Step 1: Submit URL for fresh rescan. The key chosen here is reused for
        // every read below so the whole scan stays on one key and one egress.
        $submit    = self::vt_call( $module, $user_id, 'POST', 'https://www.virustotal.com/api/v3/urls', [
            'body' => [ 'url' => $url ],
        ] );
        $key_index = (int) ( $submit['key_index'] ?? 0 );

        // Step 2: Fetch the URL report. Retry when the URL isn't indexed yet (404)
        // OR when VirusTotal returns an in-flight analysis (empty last_analysis_stats) —
        // right after a resubmit, VT may answer 200 before any engine has reported,
        // with last_analysis_stats all-zero. We wait and retry so we read real results.
        $response = null;
        $code     = 0;
        $attrs    = [];
        $stats    = [];

        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            if ( $attempt > 0 ) {
                sleep( 15 );
            }

            $response = self::vt_call(
                $module,
                $user_id,
                'GET',
                'https://www.virustotal.com/api/v3/urls/' . $url_id,
                [],
                $key_index > 0 ? $key_index : null
            )['response'];

            if ( is_wp_error( $response ) ) {
                throw new \RuntimeException( 'API request failed: ' . $response->get_error_message() );
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code === 404 ) {
                continue; // not indexed yet — wait and retry
            }

            $result = json_decode( wp_remote_retrieve_body( $response ), true );
            $attrs  = $result['data']['attributes'] ?? [];
            $stats  = $attrs['last_analysis_stats'] ?? [];

            // Real engine results present? Done. Otherwise it's an interim analysis — retry.
            if ( intval( array_sum( $stats ) ) > 0 ) {
                break;
            }
        }

        if ( $code === 404 ) {
            throw new \RuntimeException( 'URL not found in VirusTotal (even after resubmission).' );
        }

        // Interim guard: an empty / all-zero last_analysis_stats is VirusTotal's in-flight
        // state (no engine has reported yet), NOT a real "0 malicious / 0 total" verdict.
        // Storing it would overwrite the last good score and fire a bogus "-N" notification
        // that flaps back to "+N" on the next scan. Skip without touching the DB or
        // notifications, leave the previous good score in place, and report pending.
        if ( intval( array_sum( $stats ) ) === 0 ) {
            do_action( 'optee_debug_event', 'vt.scan.interim_skipped', [
                'user_id' => $user_id,
                'url'     => $url,
                'reason'  => 'empty_last_analysis_stats',
            ] );
            return [ 'malicious' => 0, 'total' => 0, 'stats' => [], 'pending' => true ];
        }

        // Step 3: Store results in DB.
        return self::store_vt_result( $user_id, $url, $url_index, $attrs, $stats );
    }

    /**
     * Persist a completed VirusTotal analysis: upsert the scan row, run the
     * score-change notification, and update the URL's meta timestamps. Shared by
     * the blocking manual scan (scan_and_store_vt) and the async cron reader
     * (scan_read_vt). Callers MUST have already applied the interim guard —
     * $stats is expected to have a non-zero engine total.
     *
     * @return array ['malicious' => int, 'total' => int, 'stats' => array]
     */
    private static function store_vt_result( int $user_id, string $url, int $url_index, array $attrs, array $stats ): array {
        global $wpdb;

        $table     = $wpdb->prefix . 'optee_vt_scans';
        $url_hash  = hash( 'sha256', $url );
        $scan_date = current_time( 'Y-m-d' );

        // Build compact vendor_results.
        $raw_vendors    = $attrs['last_analysis_results'] ?? [];
        $vendor_results = [];
        foreach ( $raw_vendors as $engine => $data ) {
            $vendor_results[ $engine ] = [
                'category' => $data['category'] ?? '',
                'result'   => $data['result'] ?? '',
            ];
        }

        // Check for score changes BEFORE storing (so first-scan detection works on repeat same-day scans).
        $today_flagged = intval( $stats['malicious'] ?? 0 ) + intval( $stats['suspicious'] ?? 0 );
        $today_total   = intval( array_sum( $stats ) );

        self::vt_check_score_change( $wpdb, $user_id, $url, $url_hash, $scan_date, $today_flagged, $today_total, $vendor_results );

        // Store results in DB (replace = upsert on user_id + url_hash + scan_date).
        $wpdb->replace(
            $table,
            [
                'user_id'        => $user_id,
                'url_hash'       => $url_hash,
                'url'            => $url,
                'scan_date'      => $scan_date,
                'total_vendors'  => intval( array_sum( $stats ) ),
                'malicious'      => intval( $stats['malicious'] ?? 0 ),
                'suspicious'     => intval( $stats['suspicious'] ?? 0 ),
                'harmless'       => intval( $stats['harmless'] ?? 0 ),
                'undetected'     => intval( $stats['undetected'] ?? 0 ),
                'timeout'        => intval( $stats['timeout'] ?? 0 ),
                'vendor_results' => wp_json_encode( $vendor_results ),
                'scanned_at'     => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' ]
        );

        // Update timestamps in user meta.
        $urls = get_user_meta( $user_id, 'optee_virustotal_urls', true ) ?: [];
        if ( isset( $urls[ $url_index ] ) ) {
            $urls[ $url_index ]['last_check'] = current_time( 'Y-m-d H:i' );
            $vt_analysis_ts = intval( $attrs['last_analysis_date'] ?? 0 );
            if ( $vt_analysis_ts > 0 ) {
                $urls[ $url_index ]['vt_analysis_date'] = $vt_analysis_ts;
            }
            update_user_meta( $user_id, 'optee_virustotal_urls', $urls );
        }

        $total     = intval( array_sum( $stats ) );
        $malicious = intval( $stats['malicious'] ?? 0 );

        do_action( 'optee_debug_event', 'vt.scan.complete', [
            'user_id'   => $user_id,
            'url'       => $url,
            'malicious' => $malicious,
            'suspicious'=> intval( $stats['suspicious'] ?? 0 ),
            'total'     => $total,
            'stats'     => $stats,
            'scan_date' => $scan_date,
        ] );

        return [
            'malicious' => $malicious,
            'total'     => $total,
            'stats'     => $stats,
        ];
    }

    /** Max cron cycles to wait for a submitted analysis before giving up. */
    private const VT_READ_MAX_ATTEMPTS = 6;

    /**
     * Async VT scan — submit step. POST the URL for a fresh analysis and record a
     * pending marker; does NOT wait for the result (scan_read_vt reads it on a
     * later cron run). Keeps cron fast: one non-blocking request, no sleep/poll.
     * Requires the acting user's context (for credentials).
     */
    public static function scan_submit_vt( int $user_id, string $url, int $url_index ): void {
        do_action( 'optee_debug_event', 'vt.submit.start', [
            'user_id'   => $user_id,
            'url'       => $url,
            'url_index' => $url_index,
        ] );

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( 'virustotal' );
        if ( ! $module ) {
            throw new \RuntimeException( 'VirusTotal module not found.' );
        }
        $creds = ModuleRegistry::get_stored_credentials( 'virustotal', 'virustotal' );
        if ( ! $creds ) {
            throw new \RuntimeException( 'VirusTotal not configured.' );
        }
        $module->set_credentials( $creds );

        $call     = self::vt_call( $module, $user_id, 'POST', 'https://www.virustotal.com/api/v3/urls', [
            'body' => [ 'url' => $url ],
        ] );
        $response = $call['response'];

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Submit request failed: ' . $response->get_error_message() );
        }

        // Verify VirusTotal actually accepted the submission BEFORE marking it
        // pending. A rejected submit that still gets a marker costs up to
        // VT_READ_MAX_ATTEMPTS wasted read cycles chasing an analysis that was
        // never queued — which starves every other URL behind it.
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 429 ) {
            self::flag_vt_quota_hit( $user_id, $response );
            throw new \RuntimeException( 'VirusTotal rate/quota limit reached (HTTP 429): ' . self::$vt_quota_code );
        }
        if ( $code < 200 || $code >= 300 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg  = $body['error']['message'] ?? ( 'HTTP ' . $code );
            throw new \RuntimeException( 'Submit rejected: ' . $msg );
        }

        // Record a pending marker so a later cron run reads the result.
        $pending = get_user_meta( $user_id, 'optee_vt_pending', true );
        $pending = is_array( $pending ) ? $pending : [];
        $pending[ $url_index ] = [
            'url'          => $url,
            'submitted_at' => time(),
            'attempts'     => 0,
            // Pin the follow-up read to the key that submitted, so a scan does not
            // change egress halfway and split itself across two allowances.
            'key_index'    => (int) ( $call['key_index'] ?? 0 ),
        ];
        update_user_meta( $user_id, 'optee_vt_pending', $pending );
    }

    /**
     * Async VT scan — read step. A single non-blocking GET of a previously-
     * submitted analysis (no sleep/poll). If VirusTotal hasn't finished yet (404
     * or interim all-zero stats), the pending marker is kept to retry next cron
     * run; after VT_READ_MAX_ATTEMPTS we give up so nothing gets stuck. Requires
     * the acting user's context.
     *
     * @return array One of:
     *   ['malicious'=>int,'total'=>int,'stats'=>array]  completed & stored
     *   ['pending'=>true,'attempts'=>int]               not ready yet
     *   ['gave_up'=>true,'attempts'=>int]               exceeded max attempts
     */
    public static function scan_read_vt( int $user_id, string $url, int $url_index ): array {
        $pending = get_user_meta( $user_id, 'optee_vt_pending', true );
        $pending = is_array( $pending ) ? $pending : [];

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( 'virustotal' );
        if ( ! $module ) {
            throw new \RuntimeException( 'VirusTotal module not found.' );
        }
        $creds = ModuleRegistry::get_stored_credentials( 'virustotal', 'virustotal' );
        if ( ! $creds ) {
            throw new \RuntimeException( 'VirusTotal not configured.' );
        }
        $module->set_credentials( $creds );
        $url_id = rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );

        // Read on the key that submitted this URL (0 = unknown, e.g. a marker
        // written before routing existed, in which case any key will do).
        $key_index = (int) ( $pending[ $url_index ]['key_index'] ?? 0 );

        $response = self::vt_call(
            $module,
            $user_id,
            'GET',
            'https://www.virustotal.com/api/v3/urls/' . $url_id,
            [],
            $key_index > 0 ? $key_index : null
        )['response'];
        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'API request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );

        // Rate limited — throw without touching the attempt counter so the pending
        // marker survives and this read is retried once the backoff clears.
        if ( $code === 429 ) {
            self::flag_vt_quota_hit( $user_id, $response );
            throw new \RuntimeException( 'VirusTotal rate/quota limit reached (HTTP 429): ' . self::$vt_quota_code );
        }

        $result = json_decode( wp_remote_retrieve_body( $response ), true );
        $attrs  = $result['data']['attributes'] ?? [];
        $stats  = $attrs['last_analysis_stats'] ?? [];

        // Ready = indexed (not 404) AND real engine results present. An all-zero
        // last_analysis_stats is VT's in-flight state, not a real "0/0" verdict —
        // same interim guard the blocking path uses (avoids score flapping).
        $ready = ( $code !== 404 ) && ( intval( array_sum( $stats ) ) > 0 );

        if ( $ready ) {
            unset( $pending[ $url_index ] );
            update_user_meta( $user_id, 'optee_vt_pending', $pending );
            return self::store_vt_result( $user_id, $url, $url_index, $attrs, $stats );
        }

        // Not ready — bump the attempt counter, give up past the cap.
        $attempts = intval( $pending[ $url_index ]['attempts'] ?? 0 ) + 1;
        if ( $attempts >= self::VT_READ_MAX_ATTEMPTS ) {
            unset( $pending[ $url_index ] );
            update_user_meta( $user_id, 'optee_vt_pending', $pending );
            do_action( 'optee_debug_event', 'vt.read.gave_up', [
                'user_id'  => $user_id,
                'url'      => $url,
                'attempts' => $attempts,
            ] );
            return [ 'gave_up' => true, 'attempts' => $attempts ];
        }

        $pending[ $url_index ]['attempts'] = $attempts;
        update_user_meta( $user_id, 'optee_vt_pending', $pending );
        return [ 'pending' => true, 'attempts' => $attempts ];
    }

    /**
     * Compare today's VT scan with yesterday's and notify if the score changed.
     */
    private static function vt_check_score_change(
        \wpdb $wpdb,
        int $user_id,
        string $url,
        string $url_hash,
        string $scan_date,
        int $today_flagged,
        int $today_total,
        array $today_vendors
    ): void {
        // Find the most recent previous scan (before today).
        $table    = $wpdb->prefix . 'optee_vt_scans';
        $prev_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT malicious, suspicious, total_vendors, vendor_results FROM {$table}
             WHERE user_id = %d AND url_hash = %s AND scan_date < %s
             ORDER BY scan_date DESC LIMIT 1",
            $user_id, $url_hash, $scan_date
        ) );

        // Also check if today's row already exists (from an earlier scan this same day).
        // Since we run BEFORE the replace, an existing today row means this is a rescan.
        $today_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT malicious, suspicious, total_vendors, vendor_results FROM {$table}
             WHERE user_id = %d AND url_hash = %s AND scan_date = %s
             LIMIT 1",
            $user_id, $url_hash, $scan_date
        ) );

        if ( $today_row ) {
            // Intraday rescan path. Compare new value against the previously stored
            // today_row; if it actually changed AND we haven't already notified for
            // this URL today, fall through to the normal compare/notify logic.
            $today_row_flagged = intval( $today_row->malicious ) + intval( $today_row->suspicious );
            if ( $today_flagged === $today_row_flagged ) {
                do_action( 'optee_debug_event', 'vt.notification.decision', [
                    'user_id'           => $user_id,
                    'url'               => $url,
                    'should_notify'     => false,
                    'reason'            => 'no_change_intraday',
                    'today_flagged'     => $today_flagged,
                    'today_row_flagged' => $today_row_flagged,
                    'scan_date'         => $scan_date,
                ] );
                return; // Identical to last scan today — nothing to report.
            }
            // Daily rate-limit: max one notification per URL per day, regardless of
            // how many intraday changes occur. Prevents noisy back-and-forth alerts.
            $last_notified = get_user_meta( $user_id, 'optee_vt_last_notified', true ) ?: [];
            if ( ! is_array( $last_notified ) ) {
                $last_notified = [];
            }
            if ( ( $last_notified[ $url_hash ] ?? '' ) === $scan_date ) {
                do_action( 'optee_debug_event', 'vt.notification.decision', [
                    'user_id'           => $user_id,
                    'url'               => $url,
                    'should_notify'     => false,
                    'reason'            => 'already_notified_today',
                    'today_flagged'     => $today_flagged,
                    'today_row_flagged' => $today_row_flagged,
                    'scan_date'         => $scan_date,
                ] );
                return;
            }
            // Use today's earlier scan as the comparison baseline for the message + vendor diff.
            $prev_flagged = $today_row_flagged;
        } elseif ( $prev_row ) {
            // First scan of the day — compare against previous day.
            $prev_flagged = intval( $prev_row->malicious ) + intval( $prev_row->suspicious );
            if ( $today_flagged === $prev_flagged ) {
                do_action( 'optee_debug_event', 'vt.notification.decision', [
                    'user_id'       => $user_id,
                    'url'           => $url,
                    'should_notify' => false,
                    'reason'        => 'no_change_from_previous_day',
                    'today_flagged' => $today_flagged,
                    'prev_flagged'  => $prev_flagged,
                    'scan_date'     => $scan_date,
                ] );
                return; // No change from previous day.
            }
        } else {
            // True first scan ever for this URL.
            if ( $today_flagged === 0 ) {
                do_action( 'optee_debug_event', 'vt.notification.decision', [
                    'user_id'       => $user_id,
                    'url'           => $url,
                    'should_notify' => false,
                    'reason'        => 'clean_on_first_scan',
                    'today_flagged' => $today_flagged,
                    'scan_date'     => $scan_date,
                ] );
                return; // Clean on first scan — nothing to report.
            }
            $prev_flagged = 0;
        }

        $is_first_scan = ! $prev_row && ! $today_row;

        $diff       = $today_flagged - $prev_flagged;
        $diff_str   = $diff > 0 ? "+{$diff}" : (string) $diff;
        $short_url  = preg_replace( '#^https?://#', '', $url );

        if ( $is_first_scan ) {
            $score_str = "{$today_flagged}/{$today_total}";
        } else {
            $score_str = "{$today_flagged}/{$today_total} ({$diff_str})";
        }

        // Find vendors that flagged (first scan) or changed to flagged (subsequent).
        $compare_row  = $today_row ?: $prev_row;
        $prev_vendors = $is_first_scan ? [] : ( json_decode( $compare_row->vendor_results, true ) ?: [] );
        $changed_vendors = [];

        foreach ( $today_vendors as $engine => $data ) {
            $result = $data['result'] ?? $data['category'] ?? '';
            if ( empty( $result ) || $result === 'clean' || $result === 'unrated' || $result === 'harmless' ) {
                continue;
            }

            if ( ! $is_first_scan ) {
                // Check if this vendor was clean/unrated/absent yesterday.
                $prev_result = $prev_vendors[ $engine ]['result'] ?? $prev_vendors[ $engine ]['category'] ?? '';
                if ( $prev_result === $result ) {
                    continue; // Same status — not new.
                }
                if ( ! empty( $prev_result ) && $prev_result !== 'clean' && $prev_result !== 'unrated' && $prev_result !== 'harmless' ) {
                    continue; // Was already flagged (different flag type) — skip.
                }
            }

            $changed_vendors[ $engine ] = $result;
        }

        // Build notification message.
        $bell_msg = $is_first_scan
            ? "VT score: {$score_str} — flagged on first scan"
            : "VT score changed: {$score_str}";

        $full_msg  = "URL: {$short_url}\n";
        $full_msg .= "Score: {$score_str}\n";
        if ( ! empty( $changed_vendors ) ) {
            $label = $is_first_scan ? "Vendor detections:" : "New vendor detections:";
            $full_msg .= "\n{$label}\n";
            foreach ( $changed_vendors as $engine => $result ) {
                $full_msg .= "  {$engine}: {$result}\n";
            }
        }

        $notif_type = $diff > 0 ? 'warning' : 'success';

        do_action( 'optee_debug_event', 'vt.notification.decision', [
            'user_id'         => $user_id,
            'url'             => $url,
            'should_notify'   => true,
            'reason'          => $is_first_scan ? 'first_scan_flagged' : 'score_changed',
            'today_flagged'   => $today_flagged,
            'prev_flagged'    => $prev_flagged,
            'diff'            => $diff,
            'changed_vendors' => array_keys( $changed_vendors ),
            'scan_date'       => $scan_date,
        ] );

        Notifications::add( $user_id, [
            'type'         => $notif_type,
            'title'        => "VirusTotal — {$short_url}",
            'message'      => $bell_msg,
            'message_full' => trim( $full_msg ),
            'time'         => current_time( 'mysql' ),
        ] );

        // Send email notification.
        self::vt_send_score_email( $user_id, $short_url, $score_str, $changed_vendors, $notif_type );

        // Mark this URL as notified today so any subsequent intraday change scans
        // don't fire a second alert. Prevents noisy 0 → 5 → 3 → 5 sequences.
        $last_notified = get_user_meta( $user_id, 'optee_vt_last_notified', true ) ?: [];
        if ( ! is_array( $last_notified ) ) {
            $last_notified = [];
        }
        $last_notified[ $url_hash ] = $scan_date;
        update_user_meta( $user_id, 'optee_vt_last_notified', $last_notified );
    }

    /**
     * Send an email when a VT score changes.
     */
    private static function vt_send_score_email(
        int $user_id,
        string $short_url,
        string $score_str,
        array $changed_vendors,
        string $type
    ): void {
        $user = get_userdata( $user_id );
        if ( ! $user || ! $user->user_email ) {
            do_action( 'optee_debug_event', 'vt.email.attempt', [
                'user_id' => $user_id,
                'sent'    => false,
                'reason'  => 'no_user_email',
                'short_url' => $short_url,
            ] );
            return;
        }

        $html = EmailTemplate::vt_score_change( $short_url, $score_str, $changed_vendors, $type );

        $defanged = EmailTemplate::defang_url( $short_url );
        $subject  = "Optee — VirusTotal score changed for {$defanged}";

        // Capture any wp_mail_failed error WordPress throws when delivery fails.
        $mail_error = null;
        $capture = function ( $wp_error ) use ( &$mail_error ) {
            $mail_error = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : (string) $wp_error;
        };
        add_action( 'wp_mail_failed', $capture );

        $html_type = function () { return 'text/html'; };
        add_filter( 'wp_mail_content_type', $html_type );
        $sent = wp_mail( $user->user_email, $subject, $html );
        remove_filter( 'wp_mail_content_type', $html_type );
        remove_action( 'wp_mail_failed', $capture );

        do_action( 'optee_debug_event', 'vt.email.attempt', [
            'user_id'   => $user_id,
            'recipient' => $user->user_email,
            'subject'   => $subject,
            'sent'      => (bool) $sent,
            'error'     => $mail_error,
            'short_url' => $short_url,
            'score'     => $score_str,
        ] );
    }

    // ──────────────────────────────────────────
    // License Revalidation Task
    // ──────────────────────────────────────────

    private static function run_license_revalidation_task(): array {
        $start    = microtime( true );
        $last_run = get_option( 'optee_auto_license_check', 0 );

        // Run every 4 hours.
        if ( ( time() - (int) $last_run ) < 14400 ) {
            return [
                'task'     => 'LICENSE',
                'detail'   => 'Skipped — checked less than 4 hours ago',
                'duration' => 0,
                'success'  => true,
                'skipped'  => true,
            ];
        }

        update_option( 'optee_auto_license_check', time(), false );

        $key = get_option( 'optee_activation_key', '' );
        if ( empty( $key ) ) {
            $dur = round( microtime( true ) - $start, 1 );
            return [
                'task'     => 'LICENSE',
                'detail'   => 'Skipped — no activation key',
                'duration' => $dur,
                'success'  => true,
                'skipped'  => true,
            ];
        }

        try {
            $domain = wp_parse_url( site_url(), PHP_URL_HOST );

            $response = wp_remote_post( 'https://api.optee.co/api/key-status', [
                'timeout' => 15,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'key'    => $key,
                    'domain' => $domain,
                ] ),
            ] );

            if ( is_wp_error( $response ) ) {
                throw new \RuntimeException( $response->get_error_message() );
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( empty( $body['valid'] ) ) {
                $dur = round( microtime( true ) - $start, 1 );
                return [
                    'task'     => 'LICENSE',
                    'detail'   => 'API rejected key: ' . ( $body['error'] ?? 'unknown' ),
                    'duration' => $dur,
                    'success'  => false,
                ];
            }

            // Update local expiry from server value.
            $server_expiry = $body['expiry_date'] ?? '';
            $local_expiry  = get_option( 'optee_activation_expiry', '' );

            if ( $server_expiry !== $local_expiry ) {
                update_option( 'optee_activation_expiry', $server_expiry );
                Logger::log( 'info', 'activation', 'License expiry synced from server.', [
                    'old' => $local_expiry ?: '(lifetime)',
                    'new' => $server_expiry ?: '(lifetime)',
                ] );
            }

            // Also sync key type.
            if ( ! empty( $body['key_type'] ) ) {
                update_option( 'optee_activation_type', $body['key_type'] );
            }

            $dur = round( microtime( true ) - $start, 1 );
            $expired_str = ! empty( $body['expired'] ) ? ' (EXPIRED)' : '';

            return [
                'task'     => 'LICENSE',
                'detail'   => 'Valid — expiry: ' . ( $server_expiry ?: 'lifetime' ) . $expired_str,
                'duration' => $dur,
                'success'  => true,
            ];
        } catch ( \Throwable $e ) {
            $dur = round( microtime( true ) - $start, 1 );
            return [
                'task'     => 'LICENSE',
                'detail'   => 'Error: ' . $e->getMessage(),
                'duration' => $dur,
                'success'  => false,
            ];
        }
    }

    // ──────────────────────────────────────────
    // Promo Check Task
    // ──────────────────────────────────────────

    /**
     * Poll the promo channel for newly-published campaigns and email the site
     * admin the first time each one appears. Runs on cron (every 30 min) so the
     * notification fires proactively — the admin doesn't have to open the Promo
     * Codes page. Notified campaign IDs are remembered so each promo emails once.
     */
    private static function run_promo_check_task(): array {
        $start    = microtime( true );
        $last_run = get_option( 'optee_auto_promo_check', 0 );

        // Run every 30 minutes.
        if ( ( time() - (int) $last_run ) < 1800 ) {
            return [
                'task'     => 'PROMO',
                'detail'   => 'Skipped — checked less than 30 min ago',
                'duration' => 0,
                'success'  => true,
                'skipped'  => true,
            ];
        }

        update_option( 'optee_auto_promo_check', time(), false );

        $key   = get_option( 'optee_activation_key', '' );
        $email = get_option( 'optee_activation_email', '' );
        if ( ! $key || ! $email ) {
            $dur = round( microtime( true ) - $start, 1 );
            return [
                'task'     => 'PROMO',
                'detail'   => 'Skipped — not activated',
                'duration' => $dur,
                'success'  => true,
                'skipped'  => true,
            ];
        }

        try {
            $response = wp_remote_post( 'https://support.optee.co/api/promo/active-campaigns', [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'key' => $key, 'email' => $email ] ),
                'timeout' => 15,
            ] );
            if ( is_wp_error( $response ) ) {
                throw new \RuntimeException( $response->get_error_message() );
            }

            $body      = json_decode( wp_remote_retrieve_body( $response ), true );
            $campaigns = ( ! empty( $body['ok'] ) && ! empty( $body['campaigns'] ) ) ? $body['campaigns'] : [];

            // Keep the sidebar Promo link in sync proactively (mirrors get_promo_campaigns()).
            $has_active = false;
            foreach ( $campaigns as $c ) {
                if ( ( $c['status'] ?? '' ) === 'active' ) {
                    $has_active = true;
                    break;
                }
            }
            update_option( 'optee_has_active_promo', $has_active ? '1' : '0', false );

            $notified = get_option( 'optee_promo_notified_ids', false );

            // First-ever run: seed the notified list with whatever is already
            // active so we don't email a burst for pre-existing promos. Only
            // campaigns that appear AFTER this baseline will notify.
            if ( $notified === false ) {
                $seed = [];
                foreach ( $campaigns as $c ) {
                    if ( ( $c['status'] ?? '' ) === 'active' && isset( $c['id'] ) ) {
                        $seed[] = $c['id'];
                    }
                }
                update_option( 'optee_promo_notified_ids', $seed, false );

                $dur = round( microtime( true ) - $start, 1 );
                return [
                    'task'     => 'PROMO',
                    'detail'   => 'Baseline set — ' . count( $seed ) . ' existing active promo(s), none emailed',
                    'duration' => $dur,
                    'success'  => true,
                ];
            }

            $notified = is_array( $notified ) ? $notified : [];

            $new_titles = [];
            foreach ( $campaigns as $c ) {
                // Only live campaigns — drafts/previews must not trigger email.
                if ( ( $c['status'] ?? '' ) !== 'active' ) {
                    continue;
                }
                $id = $c['id'] ?? null;
                if ( $id === null || in_array( $id, $notified, true ) ) {
                    continue;
                }

                self::send_new_promo_email( $c );
                $notified[]   = $id;
                $new_titles[] = $c['title'] ?? ( 'Campaign #' . $id );
            }

            // Keep the notified list bounded.
            if ( count( $notified ) > 200 ) {
                $notified = array_slice( $notified, -200 );
            }
            update_option( 'optee_promo_notified_ids', $notified, false );

            $dur = round( microtime( true ) - $start, 1 );
            if ( $new_titles ) {
                return [
                    'task'     => 'PROMO',
                    'detail'   => count( $new_titles ) . ' new promo(s) — emailed admin: ' . implode( ', ', $new_titles ),
                    'duration' => $dur,
                    'success'  => true,
                ];
            }

            return [
                'task'     => 'PROMO',
                'detail'   => 'No new promos (' . count( $campaigns ) . ' active)',
                'duration' => $dur,
                'success'  => true,
            ];
        } catch ( \Throwable $e ) {
            $dur = round( microtime( true ) - $start, 1 );
            return [
                'task'     => 'PROMO',
                'detail'   => 'Error: ' . $e->getMessage(),
                'duration' => $dur,
                'success'  => false,
            ];
        }
    }

    /**
     * Email the site admin about a newly-published promo campaign.
     */
    private static function send_new_promo_email( array $campaign ): void {
        $to = get_option( 'admin_email', '' );
        if ( ! $to ) {
            return;
        }

        $title  = $campaign['title'] ?? 'New promo code';
        $source = $campaign['traffic_source_name'] ?? '';
        $desc   = $campaign['description'] ?? '';
        $link   = Shortcode::get_url( 'promo-codes' );

        $subject = 'New promo code available' . ( $source ? " — {$source}" : '' );

        $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:600px;margin:0 auto;">'
            . '<h2 style="color:#111827;font-size:18px;margin:0 0 4px;">' . esc_html( $title ) . '</h2>'
            . ( $source ? '<p style="font-size:13px;color:#6b7280;margin:0 0 12px;">' . esc_html( $source ) . '</p>' : '' )
            . ( $desc ? '<p style="font-size:14px;color:#374151;margin:0 0 16px;">' . esc_html( $desc ) . '</p>' : '' )
            . self::promo_tiers_html( $campaign['tiers'] ?? [] )
            . '<p style="margin:20px 0 0;"><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:10px 20px;background:#2aa5ff;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">View Promo Codes</a></p>'
            . '</div>';

        $html_type = function () { return 'text/html'; };
        add_filter( 'wp_mail_content_type', $html_type );
        wp_mail( $to, '[Optee] ' . $subject, $html );
        remove_filter( 'wp_mail_content_type', $html_type );
    }

    /**
     * Render a campaign's offer tiers as an email-safe HTML block (bordered list
     * of label + audience badge + code availability). Returns '' when empty.
     */
    private static function promo_tiers_html( array $tiers ): string {
        if ( empty( $tiers ) ) {
            return '';
        }

        $ut_labels = [ 'all' => 'All Users', 'new' => 'New Users', 'existing' => 'Existing' ];
        $ut_colors = [
            'all'      => [ '#f3f4f6', '#374151' ],
            'new'      => [ '#dbeafe', '#1d4ed8' ],
            'existing' => [ '#dcfce7', '#15803d' ],
        ];

        $rows = '';
        $last = count( $tiers ) - 1;
        foreach ( array_values( $tiers ) as $i => $t ) {
            $label  = $t['label'] ?? '';
            $ut     = $t['user_type'] ?? 'all';
            $ut_txt = $ut_labels[ $ut ] ?? ucfirst( (string) $ut );
            $badge  = $ut_colors[ $ut ] ?? $ut_colors['all'];
            $codes  = intval( $t['remaining_codes'] ?? 0 );
            $border = $i < $last ? 'border-bottom:1px solid #f3f4f6;' : '';

            $rows .= '<div style="padding:12px 0;' . $border . '">'
                . '<span style="font-size:14px;font-weight:600;color:#111827;">' . esc_html( $label ) . '</span> '
                . '<span style="display:inline-block;font-size:10px;font-weight:600;padding:2px 8px;border-radius:9999px;background:' . $badge[0] . ';color:' . $badge[1] . ';vertical-align:middle;">' . esc_html( $ut_txt ) . '</span>'
                . '<div style="font-size:12px;color:#9ca3af;margin-top:3px;">' . $codes . ' code' . ( $codes === 1 ? '' : 's' ) . ' available</div>'
                . '</div>';
        }

        return '<div style="margin:16px 0 0;border:1px solid #e5e7eb;border-radius:8px;padding:4px 16px;">' . $rows . '</div>';
    }

    // ──────────────────────────────────────────
    // Staleness Helpers
    // ──────────────────────────────────────────

    /**
     * Count how many modules with credentials exist for a given task type.
     */
    private static function count_eligible_modules( string $task ): int {
        $admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
        if ( empty( $admin_ids ) ) {
            return 0;
        }

        $registry = ModuleRegistry::get_instance();
        $types    = [ 'traffic_source', 'affiliate_network', 'monetization_platform', 'antifraud_tool' ];
        $count    = 0;

        foreach ( $admin_ids as $uid ) {
            $user_id = intval( $uid );
            foreach ( $types as $type ) {
                foreach ( $registry->get_modules_by_type( $type ) as $module ) {
                    $slug = $module->get_slug();
                    $has_creds = self::as_user( $user_id, function () use ( $type, $slug ) {
                        return ! empty( ModuleRegistry::get_stored_credentials( $type, $slug ) );
                    } );
                    if ( ! $has_creds ) {
                        continue;
                    }
                    if ( $task === 'earnings' && ! method_exists( $module, 'get_account_earnings' ) ) {
                        continue;
                    }
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Count how many active VT URLs exist across all users.
     */
    private static function count_active_vt_urls(): int {
        $admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
        $count = 0;

        foreach ( $admin_ids as $uid ) {
            $urls = get_user_meta( intval( $uid ), 'optee_virustotal_urls', true ) ?: [];
            if ( ! is_array( $urls ) ) {
                continue;
            }
            foreach ( $urls as $item ) {
                if ( ( $item['status'] ?? 'active' ) === 'active' && ! empty( $item['url'] ) ) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Find the stalest module candidate for balance or earnings tasks.
     *
     * @param string $task      'balance' or 'earnings'
     * @param int    $threshold Seconds before eligible.
     * @return array|null ['user_id', 'slug', 'type', 'module']
     */
    private static function find_stalest_module( string $task, int $threshold ): ?array {
        $admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
        if ( empty( $admin_ids ) ) {
            return null;
        }

        $registry = ModuleRegistry::get_instance();
        $types    = [ 'traffic_source', 'affiliate_network', 'monetization_platform', 'antifraud_tool' ];
        $now      = time();

        $best           = null;
        $best_timestamp = PHP_INT_MAX;

        foreach ( $admin_ids as $uid ) {
            $user_id = intval( $uid );

            foreach ( $types as $type ) {
                $modules = $registry->get_modules_by_type( $type );

                foreach ( $modules as $module ) {
                    $slug = $module->get_slug();

                    // Check user has credentials (requires user context).
                    $has_creds = self::as_user( $user_id, function () use ( $type, $slug ) {
                        return ! empty( ModuleRegistry::get_stored_credentials( $type, $slug ) );
                    } );
                    if ( ! $has_creds ) {
                        continue;
                    }

                    // For earnings: module must have get_account_earnings method.
                    if ( $task === 'earnings' && ! method_exists( $module, 'get_account_earnings' ) ) {
                        continue;
                    }

                    $last_run = self::get_last_auto_run( $task, $user_id, $slug );

                    // Not yet eligible if within threshold.
                    if ( $last_run > 0 && ( $now - $last_run ) < $threshold ) {
                        continue;
                    }

                    // Oldest (or never-run = 0) wins.
                    if ( $last_run < $best_timestamp ) {
                        $best_timestamp = $last_run;
                        $best = [
                            'user_id' => $user_id,
                            'slug'    => $slug,
                            'type'    => $type,
                            'module'  => $module,
                        ];
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Find the first URL with a pending (submitted-but-not-yet-read) VT analysis.
     * Phase 1 of run_vt_scan_task() drains these before submitting anything new.
     * Stale markers (URL removed or reindexed) are cleaned up in passing.
     *
     * @return array|null ['user_id', 'url', 'index']
     */
    /**
     * @param array $skip Keys "user_id:index" already handled this cron run.
     */
    private static function find_pending_vt_url( array $skip = [] ): ?array {
        $admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
        if ( empty( $admin_ids ) ) {
            return null;
        }

        foreach ( $admin_ids as $uid ) {
            $user_id = intval( $uid );
            $pending = get_user_meta( $user_id, 'optee_vt_pending', true );
            if ( ! is_array( $pending ) || empty( $pending ) ) {
                continue;
            }

            $has_vt = self::as_user( $user_id, function () {
                return ! empty( ModuleRegistry::get_stored_credentials( 'virustotal', 'virustotal' ) );
            } );
            if ( ! $has_vt ) {
                continue;
            }

            $urls    = get_user_meta( $user_id, 'optee_virustotal_urls', true ) ?: [];
            $changed = false;

            foreach ( $pending as $index => $info ) {
                $url = $info['url'] ?? '';

                // Drop the marker if it no longer points at the same live URL.
                if ( ! $url || ! isset( $urls[ $index ] ) || ( $urls[ $index ]['url'] ?? '' ) !== $url ) {
                    unset( $pending[ $index ] );
                    $changed = true;
                    continue;
                }

                // Already handled in this batch — leave it for the next cron run.
                if ( isset( $skip[ $user_id . ':' . $index ] ) ) {
                    continue;
                }

                if ( $changed ) {
                    update_user_meta( $user_id, 'optee_vt_pending', $pending );
                }
                return [ 'user_id' => $user_id, 'url' => $url, 'index' => intval( $index ) ];
            }

            if ( $changed ) {
                update_user_meta( $user_id, 'optee_vt_pending', $pending );
            }
        }

        return null;
    }

    /**
     * Find the stalest active VT URL across all users.
     *
     * @param array $skip Keys "user_id:index" already handled this cron run.
     * @return array|null ['user_id', 'url', 'index']
     */
    private static function find_stalest_vt_url( array $skip = [] ): ?array {
        $admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
        if ( empty( $admin_ids ) ) {
            return null;
        }

        $registry = ModuleRegistry::get_instance();
        $now      = time();

        $best           = null;
        $best_timestamp = PHP_INT_MAX;

        foreach ( $admin_ids as $uid ) {
            $user_id = intval( $uid );

            // Check if user has VT credentials.
            $has_vt = self::as_user( $user_id, function () {
                return ! empty( ModuleRegistry::get_stored_credentials( 'virustotal', 'virustotal' ) );
            } );
            if ( ! $has_vt ) {
                continue;
            }

            // Get user's check interval (in minutes).
            $interval_min = intval( get_user_meta( $user_id, 'optee_vt_check_interval', true ) ) ?: 30;
            $threshold    = $interval_min * 60; // Convert to seconds.

            $urls = get_user_meta( $user_id, 'optee_virustotal_urls', true ) ?: [];
            if ( ! is_array( $urls ) ) {
                continue;
            }

            foreach ( $urls as $i => $item ) {
                // Only scan active URLs.
                if ( ( $item['status'] ?? 'active' ) !== 'active' ) {
                    continue;
                }

                // Already handled in this batch — leave it for the next cron run.
                if ( isset( $skip[ $user_id . ':' . $i ] ) ) {
                    continue;
                }

                $url      = $item['url'] ?? '';
                if ( ! $url ) {
                    continue;
                }

                $url_hash = substr( hash( 'sha256', $url ), 0, 16 );
                $last_run = self::get_last_auto_run( 'vt', $user_id, $url_hash );

                // Not yet eligible if within threshold.
                if ( $last_run > 0 && ( $now - $last_run ) < $threshold ) {
                    continue;
                }

                if ( $last_run < $best_timestamp ) {
                    $best_timestamp = $last_run;
                    $best = [
                        'user_id' => $user_id,
                        'url'     => $url,
                        'index'   => $i,
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * Get the last auto-run timestamp for a task.
     */
    private static function get_last_auto_run( string $task, int $user_id, string $slug ): int {
        $key = "optee_auto_{$task}_{$user_id}_{$slug}";
        return intval( get_option( $key, 0 ) );
    }

    /**
     * Set the last auto-run timestamp for a task.
     */
    private static function set_last_auto_run( string $task, int $user_id, string $slug ): void {
        $key = "optee_auto_{$task}_{$user_id}_{$slug}";
        update_option( $key, time(), false ); // autoload = false
    }

    /**
     * Execute a callback as a specific user, then restore the original user.
     *
     * @param int      $user_id  User ID to switch to.
     * @param callable $callback Callback to execute.
     * @return mixed Callback return value.
     */
    // ──────────────────────────────────────────
    // Insights Task — picks the oldest stale (user, network) pair per tick.
    // Anything older than INSIGHTS_THRESHOLD is eligible; one is refreshed per cron run.
    // ──────────────────────────────────────────

    /** Networks considered for the rotating refresh. */
    private const INSIGHTS_NETWORKS = [ 'mobipium', 'monetizer', 'ogads' ];

    /** Re-fetch a (user, network) pair once its last_run is older than this. */
    private const INSIGHTS_THRESHOLD = 3600; // 1 hour

    private static function run_insights_task(): array {
        $start = microtime( true );

        $candidate = self::find_oldest_stale_insight( self::INSIGHTS_THRESHOLD );

        if ( ! $candidate ) {
            return [
                'task'     => 'INSIGHTS',
                'detail'   => 'No users due for refresh',
                'duration' => round( microtime( true ) - $start, 2 ),
                'success'  => true,
                'skipped'  => true,
            ];
        }

        [ $user_id, $slug ] = $candidate;

        try {
            $result = self::as_user( $user_id, function () use ( $user_id, $slug ) {
                return InsightsService::refresh_network( $user_id, $slug );
            } );

            $detail = sprintf( '%s (user #%d): %d offers in %dms',
                $slug, $user_id, $result['count'] ?? 0, $result['ms'] ?? 0 );

            return [
                'task'     => 'INSIGHTS',
                'detail'   => $detail,
                'duration' => round( microtime( true ) - $start, 2 ),
                'success'  => ! empty( $result['success'] ),
            ];
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'cron', "Insights refresh failed ({$slug}, user #{$user_id}): " . $e->getMessage() );
            return [
                'task'     => 'INSIGHTS',
                'detail'   => "{$slug} (user #{$user_id}): exception — " . $e->getMessage(),
                'duration' => round( microtime( true ) - $start, 2 ),
                'success'  => false,
            ];
        }
    }

    /**
     * Scan every supported network for users whose last refresh is older than
     * the threshold and return the single oldest [user_id, slug] pair.
     */
    private static function find_oldest_stale_insight( int $threshold ): ?array {
        global $wpdb;
        $now    = time();
        $oldest = null; // [ ts, user_id, slug ]

        foreach ( self::INSIGHTS_NETWORKS as $slug ) {
            $meta_key = "optee_credentials_affiliate_network_{$slug}";
            $user_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''",
                $meta_key
            ) );

            foreach ( $user_ids as $uid ) {
                $uid      = (int) $uid;
                $last_run = InsightsService::get_last_run( $uid, $slug );
                if ( ( $now - $last_run ) < $threshold ) {
                    continue;
                }
                if ( $oldest === null || $last_run < $oldest[0] ) {
                    $oldest = [ $last_run, $uid, $slug ];
                }
            }
        }

        return $oldest ? [ $oldest[1], $oldest[2] ] : null;
    }

    private static function as_user( int $user_id, callable $callback ): mixed {
        $original = get_current_user_id();
        wp_set_current_user( $user_id );
        try {
            return $callback();
        } finally {
            wp_set_current_user( $original );
        }
    }

    // ──────────────────────────────────────────
    // Update Check Task
    // ──────────────────────────────────────────

    // ──────────────────────────────────────────
    // News Fetch Task
    // ──────────────────────────────────────────

    private static function run_news_fetch_task(): array {
        $start    = microtime( true );
        $last_run = get_option( 'optee_auto_news_check', 0 );

        // Run every 5 minutes.
        if ( ( time() - (int) $last_run ) < 300 ) {
            return [
                'task'    => 'NEWS',
                'detail'  => 'Skipped — checked less than 5 min ago',
                'duration' => 0,
                'success' => true,
                'skipped' => true,
            ];
        }

        update_option( 'optee_auto_news_check', time(), false );

        try {
            $response = wp_remote_get( 'https://api.optee.co/api/news', [
                'timeout' => 10,
            ] );

            if ( is_wp_error( $response ) ) {
                throw new \Exception( $response->get_error_message() );
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $news = $body['news'] ?? [];

            update_option( 'optee_news_items', $news, false );

            $dur   = round( microtime( true ) - $start, 1 );
            $count = count( $news );

            return [
                'task'    => 'NEWS',
                'detail'  => $count > 0 ? "{$count} active news item(s)" : 'No active news',
                'duration' => $dur,
                'success' => true,
            ];
        } catch ( \Exception $e ) {
            $dur = round( microtime( true ) - $start, 1 );
            return [
                'task'    => 'NEWS',
                'detail'  => 'Error: ' . $e->getMessage(),
                'duration' => $dur,
                'success' => false,
            ];
        }
    }

    // ──────────────────────────────────────────
    // Bell Notifications Task
    // ──────────────────────────────────────────

    private static function run_bell_notifications_task(): array {
        $start    = microtime( true );
        $last_run = get_option( 'optee_auto_bell_check', 0 );

        // Run every 5 minutes.
        if ( ( time() - (int) $last_run ) < 300 ) {
            return [
                'task'    => 'BELL',
                'detail'  => 'Skipped — checked less than 5 min ago',
                'duration' => 0,
                'success' => true,
                'skipped' => true,
            ];
        }

        update_option( 'optee_auto_bell_check', time(), false );

        try {
            $response = wp_remote_get( 'https://api.optee.co/api/bell-notifications', [
                'timeout' => 10,
            ] );

            if ( is_wp_error( $response ) ) {
                throw new \Exception( $response->get_error_message() );
            }

            $body          = json_decode( wp_remote_retrieve_body( $response ), true );
            $notifications = $body['notifications'] ?? [];
            $seen_ids      = get_option( 'optee_bell_seen_ids', [] );
            $seen_ids      = is_array( $seen_ids ) ? $seen_ids : [];
            $new_count     = 0;

            // Get all user IDs to push notifications to.
            $user_ids = get_users( [
                'fields' => 'ID',
                'role__in' => [ 'administrator' ],
            ] );

            foreach ( $notifications as $notif ) {
                $id = (int) ( $notif['id'] ?? 0 );
                if ( ! $id || in_array( $id, $seen_ids, true ) ) {
                    continue;
                }

                // Push to all admin users.
                foreach ( $user_ids as $uid ) {
                    Notifications::add( (int) $uid, [
                        'type'    => 'info',
                        'title'   => $notif['title'] ?? '',
                        'message' => $notif['message'] ?? '',
                        'url'     => esc_url_raw( $notif['link'] ?? '' ),
                        'time'    => current_time( 'mysql' ),
                    ] );
                }

                $seen_ids[] = $id;
                $new_count++;
            }

            // Keep only last 200 seen IDs.
            if ( count( $seen_ids ) > 200 ) {
                $seen_ids = array_slice( $seen_ids, -200 );
            }
            update_option( 'optee_bell_seen_ids', $seen_ids, false );

            $dur = round( microtime( true ) - $start, 1 );

            return [
                'task'    => 'BELL',
                'detail'  => $new_count > 0 ? "{$new_count} new bell notification(s) pushed" : 'No new bell notifications',
                'duration' => $dur,
                'success' => true,
            ];
        } catch ( \Exception $e ) {
            $dur = round( microtime( true ) - $start, 1 );
            return [
                'task'    => 'BELL',
                'detail'  => 'Error: ' . $e->getMessage(),
                'duration' => $dur,
                'success' => false,
            ];
        }
    }

    // ──────────────────────────────────────────
    // Update Check Task
    // ──────────────────────────────────────────

    private static function run_update_check_task(): array {
        $start    = microtime( true );
        $last_run = get_option( 'optee_auto_update_check', 0 );

        // Run every 5 minutes.
        if ( ( time() - (int) $last_run ) < 300 ) {
            return [
                'task'    => 'UPDATES',
                'detail'  => 'Skipped — checked less than 5 min ago',
                'duration' => 0,
                'success' => true,
                'skipped' => true,
            ];
        }

        update_option( 'optee_auto_update_check', time(), false );

        try {
            $data  = Updater::get_available_updates( true );
            $count = 0;
            foreach ( $data['updates'] as $info ) {
                if ( ! empty( $info['update_available'] ) ) {
                    $count++;
                }
            }

            // Private tools share the badge. get_tool_updates() also refreshes the
            // grant list (has_tools flag + reconcile_tool_requests), so this replaces
            // the separate get_available_tools() call below.
            $tool_updates = Updater::get_tool_updates( true );
            $count       += count( $tool_updates );

            $dur = round( microtime( true ) - $start, 1 );

            if ( $count > 0 ) {
                // Store notification for the footer badge.
                update_option( 'optee_updates_available', $count, false );
            } else {
                update_option( 'optee_updates_available', 0, false );
            }

            // Email the site admin the first time a NEW core (Optee) version appears.
            // Core only — module/extension/tool updates don't email.
            $core_emailed = self::maybe_email_core_update( $data['updates']['optee'] ?? null );

            $detail = $count > 0 ? "{$count} update(s) available" : 'Everything up to date';
            if ( $core_emailed ) {
                $detail .= ' — core update emailed to admin';
            }

            return [
                'task'    => 'UPDATES',
                'detail'  => $detail,
                'duration' => $dur,
                'success' => true,
            ];
        } catch ( \Exception $e ) {
            $dur = round( microtime( true ) - $start, 1 );
            return [
                'task'    => 'UPDATES',
                'detail'  => 'Error: ' . $e->getMessage(),
                'duration' => $dur,
                'success' => false,
            ];
        }
    }

    /**
     * Email the admin about a new core (Optee) update, once per version. Returns
     * true if an email was sent this run. Deduped via the notified-version option
     * so a pending update doesn't re-email every 5 minutes.
     *
     * @param array|null $core The 'optee' entry from the updates map, or null.
     */
    private static function maybe_email_core_update( ?array $core ): bool {
        if ( ! $core || empty( $core['update_available'] ) ) {
            return false;
        }

        $latest = (string) ( $core['latest_version'] ?? '' );
        if ( $latest === '' ) {
            return false;
        }

        // Already emailed for this exact version? Skip.
        $notified = (string) get_option( 'optee_core_update_notified_version', '' );
        if ( $notified === $latest ) {
            return false;
        }

        $current = (string) ( $core['current_version'] ?? ( defined( 'OPTEE_VERSION' ) ? OPTEE_VERSION : '' ) );
        self::send_core_update_email( $current, $latest, (string) ( $core['changelog'] ?? '' ) );

        update_option( 'optee_core_update_notified_version', $latest, false );
        return true;
    }

    /**
     * Email the site admin that a new Optee Core version is available.
     */
    private static function send_core_update_email( string $current, string $latest, string $changelog ): void {
        $to = get_option( 'admin_email', '' );
        if ( ! $to ) {
            return;
        }

        $dash    = get_permalink( (int) get_option( 'optee_dashboard_page_id' ) ) ?: home_url();
        $subject = 'Optee Core update available — v' . $latest;

        $ver_row = '<div style="display:inline-block;font-size:14px;color:#374151;background:#f3f4f6;border-radius:8px;padding:8px 14px;margin:0 0 4px;">'
            . ( $current !== '' ? '<span style="color:#9ca3af;">v' . esc_html( $current ) . '</span> &nbsp;&rarr;&nbsp; ' : '' )
            . '<strong style="color:#111827;">v' . esc_html( $latest ) . '</strong>'
            . '</div>';

        $changelog_block = '';
        if ( trim( $changelog ) !== '' ) {
            $changelog_block = '<div style="margin:16px 0 0;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;">'
                . '<div style="font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#9ca3af;margin:0 0 6px;">What&rsquo;s new</div>'
                . '<div style="font-size:13px;color:#374151;white-space:pre-line;line-height:1.5;">' . esc_html( $changelog ) . '</div>'
                . '</div>';
        }

        $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:600px;margin:0 auto;">'
            . '<h2 style="color:#111827;font-size:18px;margin:0 0 4px;">Optee Core update available</h2>'
            . '<p style="font-size:13px;color:#6b7280;margin:0 0 12px;">A new version of the Optee core plugin is ready to install.</p>'
            . $ver_row
            . $changelog_block
            . '<p style="margin:20px 0 0;"><a href="' . esc_url( $dash ) . '" style="display:inline-block;padding:10px 20px;background:#2aa5ff;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Open Dashboard</a></p>'
            . '<p style="font-size:12px;color:#9ca3af;margin:12px 0 0;">Open your dashboard and click the update badge in the footer to install.</p>'
            . '</div>';

        $html_type = function () { return 'text/html'; };
        add_filter( 'wp_mail_content_type', $html_type );
        wp_mail( $to, '[Optee] ' . $subject, $html );
        remove_filter( 'wp_mail_content_type', $html_type );
    }
}
