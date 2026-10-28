<?php

declare(strict_types=1);

namespace SimpleKuma\Guard;

/**
 * Campaign fields for Grizzly Guard. The fallback offer stays on fallback_offer_id.
 */
final class GrizzlyGuardCampaign
{
    /**
     * @param array<string, mixed> $input
     * @return array{guard_mode: string, guard_backup_domain_id: ?int, guard_backup_lp_id: ?int, guard_last_resort_url: ?string}
     */
    public static function fromInput(array $input): array
    {
        $mode = GrizzlyGuardPlan::normalizeMode((string) ($input['guard_mode'] ?? GrizzlyGuardPlan::MODE_OFF));
        $domainId = (int) ($input['guard_backup_domain_id'] ?? 0);
        $lpId = (int) ($input['guard_backup_lp_id'] ?? 0);
        $url = trim((string) ($input['guard_last_resort_url'] ?? ''));

        return [
            'guard_mode' => $mode,
            'guard_backup_domain_id' => $domainId > 0 ? $domainId : null,
            'guard_backup_lp_id' => $lpId > 0 ? $lpId : null,
            'guard_last_resort_url' => $url !== '' ? $url : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function validateFields(array $data): array
    {
        $errors = [];
        $url = trim((string) ($data['guard_last_resort_url'] ?? ''));
        if ($url !== '' && !GrizzlyGuardPlan::isHttpUrl($url)) {
            $errors['guard_last_resort_url'] = 'Last-resort URL must be an http or https address with no offer tokens.';
        }

        return $errors;
    }

    /**
     * Plain http(s) URL used only when the campaign has no destination left.
     *
     * @param array<string, mixed> $campaign
     */
    public static function lastResortUrl(array $campaign): ?string
    {
        $mode = GrizzlyGuardPlan::normalizeMode((string) ($campaign['guard_mode'] ?? GrizzlyGuardPlan::MODE_OFF));
        if ($mode === GrizzlyGuardPlan::MODE_OFF) {
            return null;
        }
        $url = trim((string) ($campaign['guard_last_resort_url'] ?? ''));
        if (!GrizzlyGuardPlan::isHttpUrl($url)) {
            return null;
        }

        return $url;
    }

    public static function isHeld(\mysqli $db, int $campaignId, string $type, int $targetId): bool
    {
        if ($campaignId <= 0 || $targetId <= 0) {
            return false;
        }
        if ($type !== 'offer' && $type !== 'landing_page') {
            return false;
        }
        $stmt = $db->prepare(
            'SELECT 1 FROM grizzly_guard_holds WHERE campaign_id = ? AND target_type = ? AND target_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isi', $campaignId, $type, $targetId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();

        return $row !== null;
    }
}
