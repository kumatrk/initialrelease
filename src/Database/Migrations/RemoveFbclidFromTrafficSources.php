<?php

declare(strict_types=1);

namespace SimpleKuma\Database\Migrations;

use mysqli;

/**
 * Migration 047 — remove fbclid from traffic_sources.tokens_json (PHP for MariaDB/MySQL compatibility).
 */
class RemoveFbclidFromTrafficSources
{
    public static function run(mysqli $db): ?string
    {
        $result = $db->query(
            "SELECT id, tokens_json FROM traffic_sources
             WHERE tokens_json IS NOT NULL
               AND tokens_json != 'null'
               AND tokens_json != '[]'
               AND (
                   JSON_SEARCH(tokens_json, 'one', 'fbclid', NULL, '$[*].parameter') IS NOT NULL
                   OR JSON_SEARCH(tokens_json, 'one', 'fbclid', NULL, '$[*].key') IS NOT NULL
               )"
        );

        if ($result === false) {
            return $db->error;
        }

        $stmt = $db->prepare('UPDATE traffic_sources SET tokens_json = ? WHERE id = ?');
        if ($stmt === false) {
            return $db->error;
        }

        while ($row = $result->fetch_assoc()) {
            $id = (int) $row['id'];
            $tokens = json_decode((string) $row['tokens_json'], true);
            if (!is_array($tokens)) {
                continue;
            }

            $filtered = [];
            foreach ($tokens as $token) {
                if (!is_array($token)) {
                    $filtered[] = $token;
                    continue;
                }
                $param = strtolower((string) ($token['parameter'] ?? $token['key'] ?? ''));
                if ($param === 'fbclid') {
                    continue;
                }
                $filtered[] = $token;
            }

            $newJson = json_encode(array_values($filtered), JSON_UNESCAPED_UNICODE);
            if ($newJson === false) {
                return 'Failed to encode tokens_json for traffic_sources.id ' . $id;
            }

            $stmt->bind_param('si', $newJson, $id);
            if (!$stmt->execute()) {
                $stmt->close();
                return $stmt->error;
            }
        }

        $stmt->close();
        return null;
    }
}
