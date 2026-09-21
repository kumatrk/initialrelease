<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use mysqli;

/**
 * Optional Meta CAPI PageView on tracked click (non-blocking).
 */
final class MetaCapiPageViewSender
{
    private const CURL_TIMEOUT_SECONDS = 2;

    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Schedule PageView after a click is stored. Never throws; never blocks the caller meaningfully.
     * Call after redirect response is committed when possible.
     */
    public function maybeSendForClick(int $campaignId, string $clickId, array $clickContext = []): void
    {
        try {
            $colCheck = $this->db->query(
                "SHOW COLUMNS FROM facebook_capi_integrations LIKE 'send_pageview_on_click'"
            );
            if (!$colCheck || $colCheck->num_rows === 0) {
                return;
            }

            $stmt = $this->db->prepare(
                "SELECT c.facebook_capi_integration_id,
                        fci.pixel_id, fci.access_token, fci.test_code, fci.send_pageview_on_click
                 FROM campaigns c
                 INNER JOIN facebook_capi_integrations fci ON fci.id = c.facebook_capi_integration_id
                 WHERE c.id = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('i', $campaignId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || empty($row['send_pageview_on_click'])) {
                return;
            }
            if (empty($row['pixel_id']) || empty($row['access_token'])) {
                return;
            }

            if ($this->alreadySent($clickId)) {
                return;
            }

            $this->markSent($clickId);

            $eventTime = time();
            $eventSourceUrl = $clickContext['event_source_url'] ?? null;
            if (empty($eventSourceUrl) && defined('BASE_URL')) {
                $eventSourceUrl = rtrim((string)BASE_URL, '/') . '/';
            }

            $userData = [];
            if (!empty($clickContext['ip'])) {
                $userData['client_ip_address'] = $clickContext['ip'];
            }
            if (!empty($clickContext['ua'])) {
                $userData['client_user_agent'] = $clickContext['ua'];
            }
            if (!empty($clickContext['fbc'])) {
                $userData['fbc'] = $clickContext['fbc'];
            }
            if (!empty($clickContext['fbp'])) {
                $userData['fbp'] = $clickContext['fbp'];
            }

            $payload = [
                'data' => [[
                    'event_name' => 'PageView',
                    'event_time' => $eventTime,
                    'event_id' => $clickId . ':pageview',
                    'action_source' => 'website',
                    'event_source_url' => $eventSourceUrl ?: 'https://www.website.com/',
                    'user_data' => $userData,
                ]],
                'access_token' => $row['access_token'],
            ];
            if (!empty($row['test_code'])) {
                $payload['test_event_code'] = $row['test_code'];
            }

            $url = "https://graph.facebook.com/v21.0/{$row['pixel_id']}/events";
            $ch = curl_init($url);
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::CURL_TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            $body = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            error_log(
                "MetaCapiPageViewSender: click_id={$clickId} campaign_id={$campaignId} "
                . "http={$httpCode}" . ($err !== '' ? " err={$err}" : '')
                . ' body=' . substr((string)$body, 0, 200)
            );
        } catch (\Throwable $e) {
            error_log('MetaCapiPageViewSender: ' . $e->getMessage());
        }
    }

    private function alreadySent(string $clickId): bool
    {
        $stmt = $this->db->prepare('SELECT extra_json FROM clicks WHERE click_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }
        $extra = json_decode($row['extra_json'] ?? '{}', true) ?: [];
        return !empty($extra['meta_pageview_sent']);
    }

    private function markSent(string $clickId): void
    {
        $stmt = $this->db->prepare('SELECT extra_json FROM clicks WHERE click_id = ? LIMIT 1');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return;
        }
        $extra = json_decode($row['extra_json'] ?? '{}', true) ?: [];
        $extra['meta_pageview_sent'] = 1;
        $json = json_encode($extra);
        $upd = $this->db->prepare('UPDATE clicks SET extra_json = ? WHERE click_id = ?');
        if (!$upd) {
            return;
        }
        $upd->bind_param('ss', $json, $clickId);
        $upd->execute();
        $upd->close();
    }
}
