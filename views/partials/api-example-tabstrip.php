<?php
/**
 * Reusable API example tab strip.
 *
 * @var list<array{
 *   id: string,
 *   label: string,
 *   summary: string,
 *   example?: string|null,
 *   reference?: bool,
 *   fields?: list<array{field: string, type: string, required: bool, description: string}>
 * }> $tabs
 * @var string $stripId
 * @var string $ariaLabel
 * @var string $copyLabel
 */
$copyLabel = $copyLabel ?? 'Copy example';
$stripId = $stripId ?? 'api-examples';
$ariaLabel = $ariaLabel ?? 'API examples';
?>
<div class="api-example-block">
    <div class="api-prompt-tabs api-tabstrip" data-api-tabstrip="<?= htmlspecialchars($stripId, ENT_QUOTES, 'UTF-8') ?>">
        <div class="api-prompt-tablist" role="tablist" aria-label="<?= htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') ?>">
            <?php foreach ($tabs as $i => $tab): ?>
            <button type="button"
                    class="api-prompt-tab<?= !empty($tab['reference']) ? ' api-prompt-tab--ref' : '' ?><?= $i === 0 ? ' is-active' : '' ?>"
                    role="tab"
                    id="<?= htmlspecialchars($stripId, ENT_QUOTES, 'UTF-8') ?>-tab-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"
                    aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                    aria-controls="<?= htmlspecialchars($stripId, ENT_QUOTES, 'UTF-8') ?>-panel-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"
                    data-prompt-tab="<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($tab['label']) ?>
            </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($tabs as $i => $tab): ?>
        <div class="api-prompt-panel api-example-panel<?= $i === 0 ? ' is-active' : '' ?>"
             role="tabpanel"
             id="<?= htmlspecialchars($stripId, ENT_QUOTES, 'UTF-8') ?>-panel-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"
             aria-labelledby="<?= htmlspecialchars($stripId, ENT_QUOTES, 'UTF-8') ?>-tab-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"
             data-prompt-panel="<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"
             <?= $i === 0 ? '' : 'hidden' ?>>
            <p class="api-prompt-summary"><?= htmlspecialchars($tab['summary']) ?></p>

            <?php if (!empty($tab['reference']) && !empty($tab['fields'])): ?>
            <div class="api-ref-table-wrap">
                <table class="table api-ref-table">
                    <thead>
                        <tr>
                            <th>Field</th>
                            <th>Type</th>
                            <th>Required</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tab['fields'] as $row): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($row['field']) ?></code></td>
                            <td><code class="api-ref-type"><?= htmlspecialchars($row['type']) ?></code></td>
                            <td><?= !empty($row['required']) ? 'Yes' : 'No' ?></td>
                            <td><?= htmlspecialchars($row['description']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php elseif (!empty($tab['example'])): ?>
            <pre class="api-code-block<?= !empty($tab['is_json']) ? ' api-json-block' : '' ?>" id="<?= htmlspecialchars($stripId, ENT_QUOTES, 'UTF-8') ?>-ex-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tab['example']) ?></pre>
            <button type="button" class="btn btn-secondary api-copy-btn" data-copy-from="<?= htmlspecialchars($stripId, ENT_QUOTES, 'UTF-8') ?>-ex-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($copyLabel) ?></button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
