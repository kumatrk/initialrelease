<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Helpers for DeepFunnel rotation_json.deep_funnel shape.
 * Does not alter DTO / LP / Split parsing.
 *
 * Public LP links use opaque link_key (not slot numbers) so funnels are harder to enumerate.
 * Slot remains a human label in the editor / Funnel stats.
 */
final class FunnelRotation
{
    public const MAX_LEVELS = 5;
    public const MAX_SLOTS = 5;

    public static function isDeepFunnelCampaign(array $campaign): bool
    {
        return ($campaign['flow_type'] ?? '') === 'DeepFunnel';
    }

    /**
     * Short opaque key for public CTA / hop links (URL-safe).
     */
    public static function generateLinkKey(): string
    {
        try {
            return bin2hex(random_bytes(4)); // 8 hex chars
        } catch (\Throwable $e) {
            return substr(str_replace(['+', '/', '='], '', base64_encode(uniqid('', true))), 0, 8);
        }
    }

    public static function normalizeLinkKey(?string $key): string
    {
        $key = strtolower(trim((string) $key));
        if ($key === '' || !preg_match('/^[a-z0-9]{6,16}$/', $key)) {
            return self::generateLinkKey();
        }
        return $key;
    }

    /**
     * @return array{levels: list<array{items: list<array>}>, offers: list<array>}|null
     */
    public static function getConfig(array $campaign): ?array
    {
        $rotation = $campaign['rotation_json'] ?? [];
        if (is_string($rotation)) {
            $rotation = json_decode($rotation, true) ?? [];
        }
        if (!is_array($rotation)) {
            return null;
        }
        $df = $rotation['deep_funnel'] ?? null;
        if (!is_array($df) || empty($df['levels']) || !is_array($df['levels'])) {
            return null;
        }
        return [
            'levels' => array_values($df['levels']),
            'offers' => isset($df['offers']) && is_array($df['offers']) ? array_values($df['offers']) : [],
        ];
    }

    /**
     * Enabled LP items for a level (1-based), with stable slot + link_key.
     *
     * @return list<array{id:int,weight:int,slot:int,link_key:string,enabled:bool,type:string}>
     */
    public static function enabledLevelItems(array $config, int $level): array
    {
        if ($level < 1 || $level > self::MAX_LEVELS) {
            return [];
        }
        $levels = $config['levels'] ?? [];
        $levelIndex = $level - 1;
        if (!isset($levels[$levelIndex]['items']) || !is_array($levels[$levelIndex]['items'])) {
            return [];
        }

        $out = [];
        foreach (array_values($levels[$levelIndex]['items']) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['enabled']) && $item['enabled'] === false) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $slot = isset($item['slot']) ? (int) $item['slot'] : ($index + 1);
            if ($slot < 1 || $slot > self::MAX_SLOTS) {
                $slot = $index + 1;
            }
            $out[] = [
                'type' => 'landing_page',
                'id' => $id,
                'weight' => (int) ($item['weight'] ?? 0),
                'slot' => $slot,
                'link_key' => self::normalizeLinkKey(isset($item['link_key']) ? (string) $item['link_key'] : null),
                'enabled' => true,
            ];
        }
        return $out;
    }

    /**
     * Opaque key on a level that means “weighted rotate among that level’s LPs”.
     */
    public static function levelRotateKey(array $config, int $level): ?string
    {
        if ($level < 1 || $level > self::MAX_LEVELS) {
            return null;
        }
        $levels = $config['levels'] ?? [];
        $idx = $level - 1;
        if (!isset($levels[$idx]) || !is_array($levels[$idx])) {
            return null;
        }
        $key = strtolower(trim((string) ($levels[$idx]['level_key'] ?? '')));
        return preg_match('/^[a-z0-9]{6,16}$/', $key) ? $key : null;
    }

    /**
     * Resolve opaque key to a specific LP (forced slot) or weighted pick within a level.
     *
     * @return array{level:int,id:int,weight:int,slot:int,link_key:string}|null
     */
    public static function resolveHopByLinkKey(array $config, string $linkKey): ?array
    {
        $linkKey = strtolower(trim($linkKey));
        if ($linkKey === '' || !preg_match('/^[a-z0-9]{6,16}$/', $linkKey)) {
            return null;
        }

        // Level rotate keys first
        foreach (array_values($config['levels'] ?? []) as $levelIdx => $level) {
            if (!is_array($level)) {
                continue;
            }
            $levelNum = $levelIdx + 1;
            $lk = strtolower(trim((string) ($level['level_key'] ?? '')));
            if ($lk !== '' && $lk === $linkKey) {
                $items = self::enabledLevelItems($config, $levelNum);
                $picked = self::pickLevelItem($items, null);
                if (!$picked) {
                    return null;
                }
                return [
                    'level' => $levelNum,
                    'id' => (int) $picked['id'],
                    'weight' => (int) $picked['weight'],
                    'slot' => (int) $picked['slot'],
                    'link_key' => (string) ($picked['link_key'] ?? $linkKey),
                ];
            }
        }

        return self::findLpByLinkKey($config, $linkKey);
    }

    /**
     * Find an enabled LP anywhere in the funnel by opaque link_key (specific LP, not level rotate).
     *
     * @return array{level:int,id:int,weight:int,slot:int,link_key:string}|null
     */
    public static function findLpByLinkKey(array $config, string $linkKey): ?array
    {
        $linkKey = strtolower(trim($linkKey));
        if ($linkKey === '') {
            return null;
        }
        foreach (array_values($config['levels'] ?? []) as $levelIdx => $level) {
            $levelNum = $levelIdx + 1;
            foreach (self::enabledLevelItems($config, $levelNum) as $item) {
                if (strtolower((string) ($item['link_key'] ?? '')) === $linkKey) {
                    return [
                        'level' => $levelNum,
                        'id' => (int) $item['id'],
                        'weight' => (int) $item['weight'],
                        'slot' => (int) $item['slot'],
                        'link_key' => (string) $item['link_key'],
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Find an enabled offer by opaque link_key.
     *
     * @return array{id:int,slot:int,link_key:string,weight:int}|null
     */
    public static function findOfferByLinkKey(array $config, string $linkKey): ?array
    {
        $linkKey = strtolower(trim($linkKey));
        if ($linkKey === '' || !preg_match('/^[a-z0-9]{6,16}$/', $linkKey)) {
            return null;
        }
        foreach (array_values($config['offers'] ?? []) as $index => $offer) {
            if (!is_array($offer)) {
                continue;
            }
            if (isset($offer['enabled']) && $offer['enabled'] === false) {
                continue;
            }
            $id = (int) ($offer['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $stored = strtolower(trim((string) ($offer['link_key'] ?? '')));
            if ($stored !== '' && $stored === $linkKey) {
                return [
                    'id' => $id,
                    'slot' => isset($offer['slot']) ? (int) $offer['slot'] : ($index + 1),
                    'link_key' => $stored,
                    'weight' => (int) ($offer['weight'] ?? 0),
                ];
            }
        }
        return null;
    }

    /**
     * Pick LP item by slot or weighted rotate within level.
     *
     * @param list<array{id:int,weight:int,slot:int,link_key?:string}> $items
     * @return array{id:int,weight:int,slot:int,link_key?:string}|null
     */
    public static function pickLevelItem(array $items, ?int $forceSlot = null): ?array
    {
        if ($items === []) {
            return null;
        }
        if ($forceSlot !== null) {
            foreach ($items as $item) {
                if ((int) $item['slot'] === $forceSlot) {
                    return $item;
                }
            }
            return null;
        }
        if (count($items) === 1) {
            return $items[0];
        }
        $totalWeight = 0;
        foreach ($items as $item) {
            $totalWeight += max(0, (int) $item['weight']);
        }
        if ($totalWeight <= 0) {
            return $items[0];
        }
        $random = mt_rand(1, $totalWeight);
        $current = 0;
        foreach ($items as $item) {
            $current += max(0, (int) $item['weight']);
            if ($random <= $current) {
                return $item;
            }
        }
        return $items[0];
    }

    public static function pathKeyAppendLp(string $pathKey, int $level, int $slot): string
    {
        $seg = $level . ':' . $slot;
        return $pathKey === '' ? $seg : ($pathKey . '>' . $seg);
    }

    public static function pathKeyAppendOffer(string $pathKey, int $slot): string
    {
        $seg = 'o:' . $slot;
        return $pathKey === '' ? $seg : ($pathKey . '>' . $seg);
    }
}
