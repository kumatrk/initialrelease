<?php

declare(strict_types=1);

namespace SimpleKuma\Edge;

use mysqli;
use SimpleKuma\Entity\Campaign;

/**
 * Publishes / removes campaign snapshots in Cloudflare KV.
 */
final class EdgeCampaignSync
{
    /** Short budget so campaign save never waits on Cloudflare long enough for nginx 502. */
    private const HOOK_CURL_TIMEOUT = 8;
    private const HOOK_CURL_CONNECT_TIMEOUT = 3;

    /** @var array<int, true> */
    private static array $pendingAfterSaveIds = [];
    private static ?mysqli $pendingAfterSaveDb = null;
    private static bool $afterSaveShutdownRegistered = false;

    private mysqli $db;
    private EdgeSettings $settings;
    private EdgeCampaignSerializer $serializer;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->settings = new EdgeSettings($db);
        $this->serializer = new EdgeCampaignSerializer($db);
    }

    private function cloudflareClientForHooks(string $accountId, string $token): CloudflareClient
    {
        return new CloudflareClient(
            $accountId,
            $token,
            self::HOOK_CURL_TIMEOUT,
            self::HOOK_CURL_CONNECT_TIMEOUT
        );
    }

    /**
     * Sync one campaign by id. Safe to call from create/update/delete hooks.
     *
     * @return array{ok: bool, message: string, action?: string}
     */
    public function syncCampaignId(int $campaignId): array
    {
        $campaignEntity = new Campaign($this->db);
        $campaign = $campaignEntity->getById($campaignId);
        if (!$campaign) {
            return $this->removeCampaignKeysByLookup($campaignId, null, []);
        }
        return $this->syncCampaign($campaign);
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array{ok: bool, message: string, action?: string}
     */
    public function syncCampaign(array $campaign): array
    {
        if (!$this->settings->isEnabled() || $this->settings->getKvNamespaceId() === '') {
            return ['ok' => true, 'message' => 'Edge sync skipped (not enabled or KV missing)', 'action' => 'skipped'];
        }

        $accountId = $this->settings->getAccountId();
        $token = $this->settings->getApiToken();
        if ($accountId === '' || $token === '') {
            return ['ok' => false, 'message' => 'Cloudflare credentials missing'];
        }

        $client = $this->cloudflareClientForHooks($accountId, $token);
        $namespaceId = $this->settings->getKvNamespaceId();
        $campaignId = (int) ($campaign['id'] ?? 0);
        $campaignKey = (string) ($campaign['campaign_key'] ?? '');

        $payload = $this->serializer->serialize($campaign);
        $slugKeys = $this->collectSlugKeys($campaign);

        if ($payload === null) {
            $result = $this->deleteKeys($client, $namespaceId, $campaignKey, $slugKeys);
            $this->updateCampaignSyncMeta($campaignId, true, null);
            $this->settings->markCampaignSync();
            return [
                'ok' => $result['ok'],
                'message' => $result['ok'] ? 'Campaign removed from edge KV (ineligible or disabled)' : $result['message'],
                'action' => 'removed',
            ];
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->updateCampaignSyncMeta($campaignId, false, 'JSON encode failed');
            return ['ok' => false, 'message' => 'Failed to encode campaign snapshot'];
        }

        if ($campaignKey !== '') {
            $put = $client->putKvValue($namespaceId, 'camp:' . $campaignKey, $json);
            if (!$put['ok']) {
                $this->updateCampaignSyncMeta($campaignId, false, $put['message']);
                return ['ok' => false, 'message' => $put['message'], 'action' => 'failed'];
            }
        }

        // Per-slug payloads carry slug_id for attribution (camp: key stays slug_id=null)
        $slugRows = $this->loadSlugRows($campaignId);
        $writtenSlugs = [];
        foreach ($slugRows as $slugRow) {
            $slugPayload = $payload;
            $slugPayload['slug_id'] = $slugRow['id'];
            $slugJson = json_encode($slugPayload, JSON_UNESCAPED_SLASHES);
            if ($slugJson === false) {
                $this->updateCampaignSyncMeta($campaignId, false, 'JSON encode failed for slug');
                return ['ok' => false, 'message' => 'Failed to encode slug snapshot'];
            }
            $put = $client->putKvValue($namespaceId, 'slug:' . $slugRow['slug'], $slugJson);
            if (!$put['ok']) {
                $this->updateCampaignSyncMeta($campaignId, false, $put['message']);
                return ['ok' => false, 'message' => $put['message'], 'action' => 'failed'];
            }
            $writtenSlugs[] = $slugRow['slug'];
        }

        // Remove stale slug keys no longer on the campaign
        foreach ($slugKeys as $oldSlug) {
            if (!in_array($oldSlug, $writtenSlugs, true)) {
                $client->deleteKvValue($namespaceId, 'slug:' . $oldSlug);
            }
        }

        $this->updateCampaignSyncMeta($campaignId, true, null);
        $this->settings->markCampaignSync();
        return ['ok' => true, 'message' => 'Campaign synced to edge KV', 'action' => 'synced'];
    }

    /**
     * Called before/after delete when we still know keys.
     *
     * @param list<string> $slugs
     * @return array{ok: bool, message: string, action?: string}
     */
    public function removeCampaignKeys(string $campaignKey, array $slugs): array
    {
        if (!$this->settings->isEnabled() || $this->settings->getKvNamespaceId() === '') {
            return ['ok' => true, 'message' => 'Edge remove skipped', 'action' => 'skipped'];
        }
        $accountId = $this->settings->getAccountId();
        $token = $this->settings->getApiToken();
        if ($accountId === '' || $token === '') {
            return ['ok' => false, 'message' => 'Cloudflare credentials missing'];
        }
        $client = $this->cloudflareClientForHooks($accountId, $token);
        return $this->deleteKeys($client, $this->settings->getKvNamespaceId(), $campaignKey, $slugs);
    }

    /**
     * @return array{synced: int, removed: int, errors: list<string>}
     */
    public function syncAllEligible(): array
    {
        $synced = 0;
        $removed = 0;
        $errors = [];

        if (!$this->columnExists('edge_enabled')) {
            return ['synced' => 0, 'removed' => 0, 'errors' => ['edge_enabled column missing — run migrations']];
        }

        $result = $this->db->query('SELECT id FROM campaigns');
        if (!$result) {
            return ['synced' => 0, 'removed' => 0, 'errors' => ['Failed to list campaigns']];
        }

        $entity = new Campaign($this->db);
        while ($row = $result->fetch_assoc()) {
            $id = (int) $row['id'];
            $campaign = $entity->getById($id);
            if (!$campaign) {
                continue;
            }
            $res = $this->syncCampaign($campaign);
            if (!$res['ok']) {
                $errors[] = "Campaign {$id}: " . $res['message'];
                continue;
            }
            if (($res['action'] ?? '') === 'removed' || ($res['action'] ?? '') === 'skipped') {
                if (($res['action'] ?? '') === 'removed') {
                    $removed++;
                }
            } else {
                $synced++;
            }
        }

        return ['synced' => $synced, 'removed' => $removed, 'errors' => $errors];
    }

    /**
     * Queue a KV sync after the HTTP response when possible (PHP-FPM).
     * Multiple saves in one request (campaign + each slug) collapse to one sync.
     */
    public static function hookAfterSave(mysqli $db, int $campaignId): void
    {
        if ($campaignId <= 0) {
            return;
        }

        self::$pendingAfterSaveIds[$campaignId] = true;
        self::$pendingAfterSaveDb = $db;

        if (self::$afterSaveShutdownRegistered) {
            return;
        }

        self::$afterSaveShutdownRegistered = true;
        register_shutdown_function([self::class, 'flushPendingAfterSaveHooks']);
    }

    /**
     * Runs pending campaign KV syncs. Prefer finishing the FastCGI response first
     * so nginx does not 502 while Cloudflare is contacted.
     */
    public static function flushPendingAfterSaveHooks(): void
    {
        $db = self::$pendingAfterSaveDb;
        $ids = array_keys(self::$pendingAfterSaveIds);
        self::$pendingAfterSaveIds = [];
        self::$pendingAfterSaveDb = null;

        if ($db === null || $ids === []) {
            return;
        }

        // Release the client/nginx wait as soon as possible on PHP-FPM.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        @ignore_user_abort(true);

        foreach ($ids as $campaignId) {
            try {
                (new self($db))->syncCampaignId($campaignId);
            } catch (\Throwable $e) {
                error_log('EdgeCampaignSync flushPendingAfterSaveHooks: ' . $e->getMessage());
            }
        }
    }

    /**
     * Re-sync every campaign that references an offer (rotation or fallback).
     */
    public static function hookOfferChanged(mysqli $db, int $offerId): void
    {
        try {
            $sync = new self($db);
            $ids = $sync->findCampaignIdsUsingOffer($offerId);
            foreach ($ids as $campaignId) {
                $sync->syncCampaignId($campaignId);
            }
        } catch (\Throwable $e) {
            error_log('EdgeCampaignSync hookOfferChanged: ' . $e->getMessage());
        }
    }

    /**
     * Re-sync every campaign that references a landing page in rotation.
     */
    public static function hookLandingPageChanged(mysqli $db, int $landingPageId): void
    {
        try {
            $sync = new self($db);
            $ref = new \SimpleKuma\Campaign\CampaignRotationReference($db);
            foreach ($ref->getCampaignsUsingLandingPage($landingPageId) as $row) {
                $sync->syncCampaignId((int) ($row['id'] ?? 0));
            }
        } catch (\Throwable $e) {
            error_log('EdgeCampaignSync hookLandingPageChanged: ' . $e->getMessage());
        }
    }

    /**
     * Re-sync every campaign assigned to a traffic source.
     */
    public static function hookTrafficSourceChanged(mysqli $db, int $trafficSourceId): void
    {
        try {
            $sync = new self($db);
            $stmt = $db->prepare('SELECT id FROM campaigns WHERE traffic_source_id = ?');
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('i', $trafficSourceId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $sync->syncCampaignId((int) $row['id']);
            }
        } catch (\Throwable $e) {
            error_log('EdgeCampaignSync hookTrafficSourceChanged: ' . $e->getMessage());
        }
    }

    /**
     * @param list<string> $slugs
     */
    public static function hookBeforeDelete(mysqli $db, string $campaignKey, array $slugs): void
    {
        try {
            $sync = new self($db);
            $sync->removeCampaignKeys($campaignKey, $slugs);
        } catch (\Throwable $e) {
            error_log('EdgeCampaignSync hookBeforeDelete: ' . $e->getMessage());
        }
    }

    /**
     * Remove all campaign KV keys while credentials are still available.
     *
     * @return array{ok: bool, message: string, removed: int}
     */
    public function purgeAllCampaignKeys(): array
    {
        if ($this->settings->getKvNamespaceId() === '') {
            return ['ok' => true, 'message' => 'No KV namespace', 'removed' => 0];
        }
        $accountId = $this->settings->getAccountId();
        $token = $this->settings->getApiToken();
        if ($accountId === '' || $token === '') {
            return ['ok' => false, 'message' => 'Cloudflare credentials missing', 'removed' => 0];
        }

        $client = $this->cloudflareClientForHooks($accountId, $token);
        $namespaceId = $this->settings->getKvNamespaceId();
        $removed = 0;
        $errors = [];

        if (!$this->columnExists('edge_enabled')) {
            return ['ok' => true, 'message' => 'edge_enabled missing', 'removed' => 0];
        }

        $result = $this->db->query('SELECT id, campaign_key FROM campaigns');
        if (!$result) {
            return ['ok' => false, 'message' => 'Failed to list campaigns', 'removed' => 0];
        }

        while ($row = $result->fetch_assoc()) {
            $key = (string) ($row['campaign_key'] ?? '');
            $slugs = $this->collectSlugKeys(['id' => (int) $row['id']]);
            $del = $this->deleteKeys($client, $namespaceId, $key, $slugs);
            if ($del['ok']) {
                $removed++;
            } else {
                $errors[] = $del['message'];
            }
        }

        return [
            'ok' => $errors === [],
            'message' => $errors === [] ? 'KV campaign keys purged' : implode('; ', $errors),
            'removed' => $removed,
        ];
    }

    /**
     * @return list<int>
     */
    private function findCampaignIdsUsingOffer(int $offerId): array
    {
        $ids = [];
        $ref = new \SimpleKuma\Campaign\CampaignRotationReference($this->db);
        foreach ($ref->getCampaignsUsingOffer($offerId) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $stmt = $this->db->prepare('SELECT id FROM campaigns WHERE fallback_offer_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $offerId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $ids[(int) $row['id']] = true;
            }
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * @return list<array{id: int, slug: string}>
     */
    private function loadSlugRows(int $campaignId): array
    {
        $out = [];
        if ($campaignId <= 0) {
            return $out;
        }
        $stmt = $this->db->prepare('SELECT id, slug FROM campaign_slugs WHERE campaign_id = ?');
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $out[] = [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $campaign
     * @return list<string>
     */
    private function collectSlugKeys(array $campaign): array
    {
        $slugs = [];
        $id = (int) ($campaign['id'] ?? 0);
        if ($id <= 0) {
            return $slugs;
        }
        $stmt = $this->db->prepare('SELECT slug FROM campaign_slugs WHERE campaign_id = ?');
        if (!$stmt) {
            return $slugs;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $slugs[] = (string) $row['slug'];
        }
        return $slugs;
    }

    /**
     * @param list<string> $slugs
     * @return array{ok: bool, message: string, action?: string}
     */
    private function deleteKeys(CloudflareClient $client, string $namespaceId, string $campaignKey, array $slugs): array
    {
        $ok = true;
        $messages = [];
        if ($campaignKey !== '') {
            $r = $client->deleteKvValue($namespaceId, 'camp:' . $campaignKey);
            if (!$r['ok']) {
                $ok = false;
                $messages[] = $r['message'];
            }
        }
        foreach ($slugs as $slug) {
            $r = $client->deleteKvValue($namespaceId, 'slug:' . $slug);
            if (!$r['ok']) {
                $ok = false;
                $messages[] = $r['message'];
            }
        }
        return [
            'ok' => $ok,
            'message' => $ok ? 'KV keys deleted' : implode('; ', $messages),
            'action' => 'removed',
        ];
    }

    /**
     * @param list<string> $slugs
     * @return array{ok: bool, message: string, action?: string}
     */
    private function removeCampaignKeysByLookup(int $campaignId, ?string $campaignKey, array $slugs): array
    {
        if ($campaignKey === null) {
            // Best effort: nothing to remove without keys
            return ['ok' => true, 'message' => 'Campaign already gone', 'action' => 'removed'];
        }
        return $this->removeCampaignKeys($campaignKey, $slugs);
    }

    private function updateCampaignSyncMeta(int $campaignId, bool $ok, ?string $error): void
    {
        if ($campaignId <= 0 || !$this->columnExists('edge_synced_at')) {
            return;
        }
        $err = $ok ? null : mb_substr((string) $error, 0, 500);
        $stmt = $this->db->prepare(
            'UPDATE campaigns SET edge_synced_at = UTC_TIMESTAMP(), edge_sync_error = ? WHERE id = ?'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('si', $err, $campaignId);
        $stmt->execute();
    }

    private function columnExists(string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }
        $col = $this->db->real_escape_string($column);
        $result = $this->db->query("SHOW COLUMNS FROM campaigns LIKE '{$col}'");
        $cache[$column] = $result && $result->num_rows > 0;
        return $cache[$column];
    }
}
