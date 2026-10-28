<?php

declare(strict_types=1);

namespace SimpleKuma\Guard;

use SimpleKuma\Settings\SettingsManager;

/**
 * Checks active campaigns and pulls a dead link only when traffic still has a place to go.
 * Deep Funnel campaigns are skipped. Edge campaigns keep their tracking domain.
 */
final class GrizzlyGuardRunner
{
    private const BATCH = 20;
    private const MAX_PROBES = 40;
    private const ALERT_AGAIN_HOURS = 6;

    private SettingsManager $settings;
    /** @var array<string, string> */
    private array $health = [];
    /** @var array<string, string> */
    private array $reason = [];
    /** @var array<int, string>|null */
    private ?array $domains = null;
    private int $probes = 0;
    private int $blacklistCalls = 0;
    private string $mainHost = '';

    public function __construct(private \mysqli $db)
    {
        $this->settings = new SettingsManager($db);
        $base = defined('BASE_URL') ? (string) BASE_URL : '';
        $host = parse_url($base, PHP_URL_HOST);
        $this->mainHost = is_string($host) ? strtolower($host) : '';
    }

    /**
     * @return array{status: string, checked: int, actions: int}
     */
    public static function run(\mysqli $db, int $budgetSeconds = 40): array
    {
        $runner = new self($db);
        return $runner->execute($budgetSeconds);
    }

    /**
     * @return array{status: string, checked: int, actions: int}
     */
    private function execute(int $budgetSeconds): array
    {
        if (!$this->tablesReady()) {
            return ['status' => 'missing', 'checked' => 0, 'actions' => 0];
        }
        if (!GrizzlyGuardSettings::isEnabled($this->settings)) {
            return ['status' => 'off', 'checked' => 0, 'actions' => 0];
        }

        $lock = $this->db->query("SELECT GET_LOCK('grizzly_guard', 1) AS locked");
        $gotLock = $lock && ($row = $lock->fetch_assoc()) && (int) ($row['locked'] ?? 0) === 1;
        if (!$gotLock) {
            return ['status' => 'busy', 'checked' => 0, 'actions' => 0];
        }

        $checked = 0;
        $actions = 0;
        try {
            $this->db->query('DELETE FROM grizzly_guard_log WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY)');
            $actions += $this->watchDomains();
            $actions += GrizzlyGuardAnomaly::watch(
                $this->db,
                GrizzlyGuardSettings::anomalyConfig($this->settings),
                function (string $message, string $action, string $label, string $detail): int {
                    if (!$this->logOnce(0, $action, $label, $detail)) {
                        return 0;
                    }
                    $this->notify($message);

                    return 1;
                }
            );
            $started = time();
            $cursor = (int) $this->settings->get(GrizzlyGuardSettings::KEY_CURSOR, '0');
            $rows = $this->fetchBatch($cursor);
            if ($rows === [] && $cursor > 0) {
                $cursor = 0;
                $this->settings->set(GrizzlyGuardSettings::KEY_CURSOR, '0');
                $rows = $this->fetchBatch(0);
            }
            $lastId = $cursor;
            foreach ($rows as $campaign) {
                if ((time() - $started) >= $budgetSeconds || $this->probes >= self::MAX_PROBES) {
                    break;
                }
                $lastId = (int) $campaign['id'];
                $checked++;
                $actions += $this->processCampaign($campaign);
            }
            $this->settings->set(GrizzlyGuardSettings::KEY_CURSOR, (string) $lastId);
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('grizzly_guard')");
        }

        return ['status' => 'ok', 'checked' => $checked, 'actions' => $actions];
    }

    private function tablesReady(): bool
    {
        $mode = $this->db->query("SHOW COLUMNS FROM campaigns LIKE 'guard_mode'");
        $holds = $this->db->query("SHOW TABLES LIKE 'grizzly_guard_holds'");

        return $mode && $mode->num_rows > 0 && $holds && $holds->num_rows > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchBatch(int $afterId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, flow_type, rotation_json, tracking_domain_id, fallback_offer_id,
                    edge_enabled, guard_mode, guard_backup_domain_id, guard_backup_lp_id,
                    guard_last_resort_url, guard_saved_domain_id
             FROM campaigns
             WHERE status = 'active' AND guard_mode IN ('backups', 'full') AND id > ?
             ORDER BY id ASC
             LIMIT " . self::BATCH
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $afterId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @param array<string, mixed> $campaign
     */
    private function processCampaign(array $campaign): int
    {
        if ((string) ($campaign['flow_type'] ?? '') === 'DeepFunnel') {
            return 0;
        }

        $actions = 0;
        $campaignId = (int) $campaign['id'];
        $mode = GrizzlyGuardPlan::normalizeMode((string) ($campaign['guard_mode'] ?? 'off'));
        $name = (string) ($campaign['name'] ?? ('Campaign ' . $campaignId));
        $rotation = $campaign['rotation_json'] ?? [];
        if (is_string($rotation)) {
            $decoded = json_decode($rotation, true);
            $rotation = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($rotation)) {
            $rotation = [];
        }

        $offerIds = GrizzlyGuardPlan::collectIds($rotation, 'offer');
        $lpIds = GrizzlyGuardPlan::collectIds($rotation, 'landing_page');
        $fallbackId = (int) ($campaign['fallback_offer_id'] ?? 0);
        $backupLpId = (int) ($campaign['guard_backup_lp_id'] ?? 0);
        $offers = $this->loadRows('offers', array_merge($offerIds, $fallbackId > 0 ? [$fallbackId] : []));
        $lps = $this->loadRows('landing_pages', array_merge($lpIds, $backupLpId > 0 ? [$backupLpId] : []));

        $backupOfferUp = $fallbackId > 0 && $this->urlHealth((string) ($offers[$fallbackId]['url'] ?? '')) === 'up';
        $backupLpUp = $backupLpId > 0 && $this->urlHealth((string) ($lps[$backupLpId]['url'] ?? '')) === 'up';
        $lastResort = GrizzlyGuardCampaign::lastResortUrl($campaign);
        $lastResortReady = $lastResort !== null && $this->urlHealth($lastResort) === 'up';

        $dirty = false;
        $actions += $this->reviewItems(
            $campaignId,
            $name,
            $mode,
            $rotation,
            $dirty,
            'offer',
            $offerIds,
            $offers,
            $fallbackId,
            $backupOfferUp,
            $lastResortReady
        );
        $actions += $this->reviewItems(
            $campaignId,
            $name,
            $mode,
            $rotation,
            $dirty,
            'landing_page',
            $lpIds,
            $lps,
            $backupLpId,
            $backupLpUp,
            $lastResortReady
        );

        $lpHolds = $this->holdIds($campaignId, 'landing_page');
        if ($lpHolds !== [] && $backupLpUp) {
            [$rotation, $added] = GrizzlyGuardPlan::ensureBackupLandingPage($rotation, $backupLpId);
            if ($added) {
                $dirty = true;
                $this->log($campaignId, 'use_backup_lp', (string) ($lps[$backupLpId]['name'] ?? 'Backup landing page'), 'Landing page backup is in the rotation');
                $this->notify($name . ': a landing page is down. Traffic can use the backup landing page.');
                $actions++;
            }
        } elseif ($lpHolds === []) {
            [$rotation, $removed] = GrizzlyGuardPlan::removeGuardAdded($rotation);
            $dirty = $dirty || $removed;
        }

        if ($dirty) {
            $json = json_encode($rotation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $stmt = $this->db->prepare('UPDATE campaigns SET rotation_json = ? WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('si', $json, $campaignId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        return $actions;
    }

    /**
     * @param array<mixed> $rotation
     * @param list<int> $ids
     * @param array<int, array{name: string, url: string}> $rows
     */
    private function reviewItems(
        int $campaignId,
        string $campaignName,
        string $mode,
        array &$rotation,
        bool &$dirty,
        string $kind,
        array $ids,
        array $rows,
        int $backupId,
        bool $backupHealthy,
        bool $lastResortReady
    ): int {
        $actions = 0;
        $healthById = [];
        foreach ($ids as $id) {
            $healthById[$id] = $this->urlHealth((string) ($rows[$id]['url'] ?? ''));
        }

        foreach ($ids as $id) {
            $health = $healthById[$id] ?? 'unknown';
            $label = (string) ($rows[$id]['name'] ?? ($kind === 'offer' ? 'Offer' : 'Landing page'));
            $reason = $this->reason[(string) ($rows[$id]['url'] ?? '')] ?? 'Link is down';
            if ($health === 'up') {
                if ($this->forgetHold($campaignId, $kind, $id)) {
                    [$rotation, $changed] = GrizzlyGuardPlan::applyEnabled($rotation, $kind, $id, true);
                    $dirty = $dirty || $changed;
                    $this->log($campaignId, $kind === 'offer' ? 'release_offer' : 'release_lp', $label, 'Link is responding again');
                    $this->notify($campaignName . ': ' . $label . ' is responding again and was put back in rotation.');
                    $actions++;
                }
                continue;
            }
            if ($health !== 'down') {
                continue;
            }

            $otherHealthy = 0;
            foreach ($healthById as $otherId => $otherHealth) {
                if ($otherId !== $id && $otherHealth === 'up') {
                    $otherHealthy++;
                }
            }
            $action = GrizzlyGuardPlan::itemAction($mode, $health, $backupHealthy, $otherHealthy, $lastResortReady);
            if ($action === 'hold') {
                $inserted = $this->rememberHold($campaignId, $kind, $id, $reason);
                [$rotation, $changed] = GrizzlyGuardPlan::applyEnabled($rotation, $kind, $id, false);
                $dirty = $dirty || $changed;
                if ($inserted || $changed) {
                    $this->log($campaignId, $kind === 'offer' ? 'hold_offer' : 'hold_lp', $label, $reason);
                    $this->notify($campaignName . ': ' . $label . ' was pulled. ' . $reason . '.');
                    $actions++;
                }
                continue;
            }

            $releasedHold = false;
            if ($this->forgetHold($campaignId, $kind, $id)) {
                [$rotation, $changed] = GrizzlyGuardPlan::applyEnabled($rotation, $kind, $id, true);
                $dirty = $dirty || $changed;
                $this->log($campaignId, $kind === 'offer' ? 'release_offer' : 'release_lp', $label, 'No healthy backup remains, so traffic stays on the original link');
                $actions++;
                $releasedHold = true;
            }
            $leftAction = $kind === 'offer' ? 'left_offer' : 'left_lp';
            if (!$releasedHold && $this->logOnce($campaignId, $leftAction, $label, $reason . '. Traffic was left in place')) {
                $this->notify($campaignName . ': ' . $label . ' looks down, and there is no healthy backup. Traffic was left in place.');
                $actions++;
            }
        }

        return $actions;
    }

    /**
     * Probe the backup list and each guarded campaign's current domain.
     * Records health for the click path. Does not change tracking_domain_id.
     */
    private function watchDomains(): int
    {
        $this->releaseRewrittenDomains();
        $verified = $this->domains();
        $poolHosts = [];
        foreach (GrizzlyGuardSettings::domainPool($this->settings) as $id) {
            if (isset($verified[$id])) {
                $poolHosts[] = $verified[$id];
            }
        }

        $hosts = $poolHosts;
        $result = $this->db->query(
            "SELECT DISTINCT tracking_domain_id FROM campaigns
             WHERE status = 'active' AND guard_mode IN ('backups', 'full')"
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $id = $row['tracking_domain_id'];
                if ($id === null || $id === '') {
                    if ($this->mainHost !== '') {
                        $hosts[] = $this->mainHost;
                    }
                    continue;
                }
                $id = (int) $id;
                if (isset($verified[$id])) {
                    $hosts[] = $verified[$id];
                }
            }
        }

        $health = [];
        foreach (array_values(array_unique($hosts)) as $host) {
            if ($this->probes >= self::MAX_PROBES) {
                break;
            }
            $health[$host] = $this->hostHealth($host);
        }

        $stored = GrizzlyGuardSettings::freshHealth($this->settings, 86400 * 7) ?? [];
        foreach ($health as $host => $status) {
            $stored[$host] = $status;
        }
        GrizzlyGuardSettings::saveHealth($this->settings, $stored);

        $actions = 0;
        foreach ($health as $host => $status) {
            if ($status !== 'down') {
                continue;
            }
            $next = GrizzlyGuardPlan::nextHealthyHost($poolHosts, $stored, $host, false, []);
            if ($next !== null) {
                $detail = $host . ' is down or listed. Visits that still arrive will use ' . $next . '. The ad URL was not changed.';
            } else {
                $detail = $host . ' is down or listed. No backup domain is up. The ad URL was not changed.';
            }
            if ($this->logOnce(0, 'domain_down', $host, $detail)) {
                $this->notify($detail);
                $actions++;
            }
        }

        return $actions;
    }

    /**
     * The first build rewrote tracking_domain_id. Put those campaigns back
     * on the domain the user had saved, then stop rewriting it.
     */
    private function releaseRewrittenDomains(): void
    {
        $result = $this->db->query(
            'SELECT id, guard_saved_domain_id FROM campaigns WHERE guard_saved_domain_id IS NOT NULL'
        );
        if (!$result) {
            return;
        }
        while ($row = $result->fetch_assoc()) {
            $campaignId = (int) $row['id'];
            $saved = (int) $row['guard_saved_domain_id'];
            if ($saved <= 0) {
                $stmt = $this->db->prepare('UPDATE campaigns SET tracking_domain_id = NULL, guard_saved_domain_id = NULL WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $campaignId);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $this->db->prepare('UPDATE campaigns SET tracking_domain_id = ?, guard_saved_domain_id = NULL WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('ii', $saved, $campaignId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            $this->log($campaignId, 'restore_domain', 'saved domain', 'Stopped rewriting the campaign tracking domain');
        }
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{name: string, url: string}>
     */
    private function loadRows(string $table, array $ids): array
    {
        if ($table !== 'offers' && $table !== 'landing_pages') {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $result = $this->db->query("SELECT id, name, url FROM {$table} WHERE id IN ({$in})");
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[(int) $row['id']] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'url' => (string) ($row['url'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function domains(): array
    {
        if ($this->domains !== null) {
            return $this->domains;
        }
        $this->domains = [];
        $result = $this->db->query("SELECT id, domain FROM tracking_domains WHERE status IN ('verified', 'verified_manual')");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $host = $this->hostOnly((string) ($row['domain'] ?? ''));
                if ($host !== '') {
                    $this->domains[(int) $row['id']] = $host;
                }
            }
        }

        return $this->domains;
    }

    private function hostOnly(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        if (!str_contains($domain, '://')) {
            $domain = 'http://' . $domain;
        }
        $host = parse_url($domain, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    private function hostHealth(string $host): string
    {
        if ($host === '') {
            return 'unknown';
        }
        $https = $this->urlHealth('https://' . $host . '/');
        if ($https === 'up' || $https === 'unknown') {
            return $https;
        }

        return $this->urlHealth('http://' . $host . '/');
    }

    private function urlHealth(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'unknown';
        }
        if (isset($this->health[$url])) {
            return $this->health[$url];
        }
        if (!GrizzlyGuardPlan::isProbeUrl($url) || $this->probes >= self::MAX_PROBES) {
            $this->health[$url] = 'unknown';
            return 'unknown';
        }
        $this->probes++;
        $status = $this->httpStatus($url);
        $health = GrizzlyGuardPlan::classifyHttp($status['code'], $status['failed']);
        $this->reason[$url] = $status['failed'] ? 'Link did not respond' : 'Link is down';
        if ($health === 'up' || $health === 'unknown') {
            $listed = $this->blacklist($url);
            if ($listed !== '') {
                $health = 'down';
                $this->reason[$url] = $listed;
            }
        }
        $this->health[$url] = $health;

        return $health;
    }

    /**
     * @return array{code: int, failed: bool}
     */
    private function httpStatus(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'failed' => true];
        }
        $code = $this->curlStatus($url, true);
        if ($code === 405 || $code === 501) {
            $code = $this->curlStatus($url, false);
        }

        return ['code' => $code, 'failed' => $code === 0];
    }

    private function curlStatus(string $url, bool $head): int
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return 0;
        }
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => $head,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; GrizzlyGuard/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0) {
            return 0;
        }

        return $code;
    }

    private function blacklist(string $url): string
    {
        if ($this->blacklistCalls >= 12) {
            return '';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || !str_contains($host, '.')) {
            return '';
        }
        $gsb = GrizzlyGuardSettings::secret($this->settings, GrizzlyGuardSettings::KEY_GSB);
        if ($gsb !== '') {
            $this->blacklistCalls++;
            if ($this->safeBrowsingListed($url, $gsb)) {
                return 'Listed by Google Safe Browsing';
            }
        }
        $vt = GrizzlyGuardSettings::secret($this->settings, GrizzlyGuardSettings::KEY_VT);
        if ($vt !== '' && !filter_var($host, FILTER_VALIDATE_IP)) {
            $cacheKey = 'vt:' . $host;
            if (!isset($this->health[$cacheKey])) {
                $this->blacklistCalls++;
                $this->health[$cacheKey] = $this->virusTotalListed($host, $vt) ? 'listed' : 'clear';
            }
            if ($this->health[$cacheKey] === 'listed') {
                return 'Listed by VirusTotal';
            }
        }

        return '';
    }

    private function safeBrowsingListed(string $url, string $key): bool
    {
        $payload = json_encode([
            'client' => ['clientId' => 'simplekuma', 'clientVersion' => '1.0'],
            'threatInfo' => [
                'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE'],
                'platformTypes' => ['ANY_PLATFORM'],
                'threatEntryTypes' => ['URL'],
                'threatEntries' => [['url' => $url]],
            ],
        ]);
        if ($payload === false) {
            return false;
        }
        $endpoint = 'https://safebrowsing.googleapis.com/v4/threatMatches:find?key=' . rawurlencode($key);
        $body = $this->curlBody($endpoint, $payload, []);
        if ($body === null) {
            return false;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) && !empty($decoded['matches']);
    }

    private function virusTotalListed(string $host, string $key): bool
    {
        $body = $this->curlBody(
            'https://www.virustotal.com/api/v3/domains/' . rawurlencode($host),
            null,
            ['x-apikey: ' . $key]
        );
        if ($body === null) {
            return false;
        }
        $decoded = json_decode($body, true);
        $malicious = (int) ($decoded['data']['attributes']['last_analysis_stats']['malicious'] ?? 0);

        return $malicious >= 3;
    }

    /**
     * @param list<string> $headers
     */
    private function curlBody(string $url, ?string $payload, array $headers): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $headers[] = 'Accept: application/json';
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $code < 200 || $code >= 300) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $provider = '';
            $action = '';
            if (str_contains($host, 'safebrowsing.googleapis.com')) {
                $provider = 'safe_browsing';
                $action = 'Check a URL';
            } elseif (str_contains($host, 'virustotal.com')) {
                $provider = 'virustotal';
                $action = 'Check a domain';
            }
            if ($provider !== '') {
                \SimpleKuma\Support\ApiFailureLog::record(
                    $this->db,
                    $provider,
                    $action,
                    $code > 0 ? $code : null,
                    $code > 0 ? 'HTTP ' . $code : 'Could not reach the service.'
                );
            }
            return null;
        }

        return $body;
    }

    private function rememberHold(int $campaignId, string $type, int $targetId, string $reason): bool
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO grizzly_guard_holds (campaign_id, target_type, target_id, reason) VALUES (?, ?, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isis', $campaignId, $type, $targetId, $reason);
        $stmt->execute();
        $inserted = $stmt->affected_rows === 1;
        $stmt->close();

        return $inserted;
    }

    private function forgetHold(int $campaignId, string $type, int $targetId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM grizzly_guard_holds WHERE campaign_id = ? AND target_type = ? AND target_id = ?'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isi', $campaignId, $type, $targetId);
        $stmt->execute();
        $removed = $stmt->affected_rows > 0;
        $stmt->close();

        return $removed;
    }

    /**
     * @return list<int>
     */
    private function holdIds(int $campaignId, string $type): array
    {
        $stmt = $this->db->prepare('SELECT target_id FROM grizzly_guard_holds WHERE campaign_id = ? AND target_type = ?');
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('is', $campaignId, $type);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['target_id'];
        }
        $stmt->close();

        return $ids;
    }

    private function log(int $campaignId, string $action, string $label, string $detail): void
    {
        $label = $this->clip($label, 250);
        $detail = $this->clip($detail, 480);
        $stmt = $this->db->prepare(
            'INSERT INTO grizzly_guard_log (campaign_id, action, target_label, detail) VALUES (?, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isss', $campaignId, $action, $label, $detail);
        $stmt->execute();
        $stmt->close();
    }

    private function logOnce(int $campaignId, string $action, string $label, string $detail): bool
    {
        $label = $this->clip($label, 250);
        $detail = $this->clip($detail, 480);
        $stmt = $this->db->prepare(
            'SELECT id FROM grizzly_guard_log
             WHERE campaign_id = ? AND action = ? AND target_label = ?
               AND created_at > (UTC_TIMESTAMP() - INTERVAL ' . self::ALERT_AGAIN_HOURS . ' HOUR)
             LIMIT 1'
        );
        if ($stmt) {
            $stmt->bind_param('iss', $campaignId, $action, $label);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_row();
            $stmt->close();
            if ($existing) {
                return false;
            }
        }
        $this->log($campaignId, $action, $label, $detail);

        return true;
    }

    private function clip(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }

    private function notify(string $message): void
    {
        $channel = trim((string) $this->settings->get(GrizzlyGuardSettings::KEY_ALERT, ''));
        $message = $this->clip($message, 500);
        try {
            \SimpleKuma\Notify\NotificationDispatcher::sendAlert($this->db, 'Grizzly Guard', $message, $channel);
        } catch (\Throwable $e) {
            error_log('Grizzly Guard alert failed: ' . $e->getMessage());
        }
    }
}
