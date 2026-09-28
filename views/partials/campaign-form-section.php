<?php
/**
 * Reusable campaign editor section shell (expanded by default).
 *
 * Expected vars:
 * - string $sectionId
 * - string $sectionTitle
 * - string $sectionSubtitle (optional)
 * - string $sectionBodyHtml
 * - bool $sectionOpen (default true)
 * - string $sectionExtraClass (optional)
 * - string $sectionExtraAttrs (optional raw attributes, e.g. style="display:none")
 */
$sectionId = (string) ($sectionId ?? '');
$sectionTitle = (string) ($sectionTitle ?? '');
$sectionSubtitle = (string) ($sectionSubtitle ?? '');
$sectionBodyHtml = (string) ($sectionBodyHtml ?? '');
$sectionOpen = $sectionOpen ?? true;
$sectionExtraClass = trim((string) ($sectionExtraClass ?? ''));
$sectionExtraAttrs = trim((string) ($sectionExtraAttrs ?? ''));
$class = 'campaign-form-section' . ($sectionExtraClass !== '' ? ' ' . $sectionExtraClass : '');
?>
<details class="<?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>"
         id="<?= htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8') ?>"
         <?= $sectionOpen ? 'open' : '' ?>
         <?= $sectionExtraAttrs !== '' ? $sectionExtraAttrs : '' ?>>
    <summary class="campaign-form-section__header">
        <span class="campaign-form-section__title"><?= htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($sectionSubtitle !== ''): ?>
            <span class="campaign-form-section__subtitle"><?= htmlspecialchars($sectionSubtitle, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </summary>
    <div class="campaign-form-section__body">
        <?= $sectionBodyHtml ?>
    </div>
</details>
