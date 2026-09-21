<?php
/**
 * Complete API reference — all request fields and response variants per resource.
 *
 * @var string $apiBaseUrl
 */
use SimpleKuma\Api\ApiResourceReference;

$resources = ApiResourceReference::all();
?>
<section id="api-reference" class="api-doc-section">
    <h3>Complete API reference</h3>
    <p>
        Every field you can send and every response shape the API returns — grouped by resource.
        Use <a href="#api-examples">Request examples</a> for copy-paste curl; use this section when
        building parsers or agent prompts.
    </p>

    <div class="api-prompt-tabs api-tabstrip api-complete-ref-tabs" data-api-tabstrip="complete-ref">
        <div class="api-prompt-tablist api-complete-ref-tablist" role="tablist" aria-label="API resources">
            <?php foreach ($resources as $i => $resource): ?>
            <button type="button"
                    class="api-prompt-tab<?= $i === 0 ? ' is-active' : '' ?>"
                    role="tab"
                    id="complete-ref-tab-<?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?>"
                    aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                    aria-controls="complete-ref-panel-<?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?>"
                    data-prompt-tab="<?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($resource['label']) ?>
            </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($resources as $i => $resource): ?>
        <?php
        $innerStripId = 'complete-ref-inner-' . $resource['id'];
        $hasRequest = !empty($resource['request_fields']);
        $hasResponse = !empty($resource['response_sections']);
        $hasSamples = !empty($resource['samples']);
        ?>
        <div class="api-prompt-panel api-complete-ref-panel<?= $i === 0 ? ' is-active' : '' ?>"
             role="tabpanel"
             id="complete-ref-panel-<?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?>"
             aria-labelledby="complete-ref-tab-<?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?>"
             data-prompt-panel="<?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?>"
             <?= $i === 0 ? '' : 'hidden' ?>>
            <p class="api-prompt-summary"><?= htmlspecialchars($resource['summary']) ?></p>

            <?php if (!empty($resource['endpoints'])): ?>
            <div class="api-endpoints-wrap">
                <h4 class="api-ref-subheading">Endpoints</h4>
                <table class="table api-endpoints-table">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Path</th>
                            <th>Body / params</th>
                            <th>Response</th>
                            <th>HTTP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resource['endpoints'] as $ep): ?>
                        <tr>
                            <td><code class="api-method api-method--<?= strtolower(htmlspecialchars($ep['method'])) ?>"><?= htmlspecialchars($ep['method']) ?></code></td>
                            <td><code><?= htmlspecialchars($ep['path']) ?></code></td>
                            <td><?= htmlspecialchars($ep['body']) ?></td>
                            <td><?= htmlspecialchars($ep['response']) ?></td>
                            <td><?= htmlspecialchars($ep['http']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="api-ref-detail">
                <div class="api-ref-detail-header">
                    <h4 class="api-ref-detail-title">Detailed reference</h4>
                    <p class="api-ref-detail-hint">Click a view below — each opens a different table or JSON sample for this resource.</p>
                </div>

            <div class="api-prompt-tabs api-tabstrip api-complete-ref-inner" data-api-tabstrip="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>">
                <div class="api-prompt-tablist api-ref-segmented" role="tablist" aria-label="<?= htmlspecialchars($resource['label'], ENT_QUOTES, 'UTF-8') ?> reference views">
                    <?php if ($hasRequest): ?>
                    <button type="button"
                            class="api-prompt-tab api-ref-segment is-active"
                            role="tab"
                            id="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-tab-request"
                            aria-selected="true"
                            aria-controls="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-panel-request"
                            data-prompt-tab="request">
                        <span class="api-ref-segment-label">Request fields</span>
                        <span class="api-ref-segment-desc">What to send in POST/PATCH</span>
                    </button>
                    <?php endif; ?>
                    <?php if ($hasResponse): ?>
                    <button type="button"
                            class="api-prompt-tab api-ref-segment<?= !$hasRequest ? ' is-active' : '' ?>"
                            role="tab"
                            id="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-tab-response"
                            aria-selected="<?= !$hasRequest ? 'true' : 'false' ?>"
                            aria-controls="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-panel-response"
                            data-prompt-tab="response">
                        <span class="api-ref-segment-label">Response fields</span>
                        <span class="api-ref-segment-desc">What comes back in data</span>
                    </button>
                    <?php endif; ?>
                    <?php if ($hasSamples): ?>
                    <button type="button"
                            class="api-prompt-tab api-ref-segment<?= !$hasRequest && !$hasResponse ? ' is-active' : '' ?>"
                            role="tab"
                            id="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-tab-samples"
                            aria-selected="<?= !$hasRequest && !$hasResponse ? 'true' : 'false' ?>"
                            aria-controls="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-panel-samples"
                            data-prompt-tab="samples">
                        <span class="api-ref-segment-label">JSON samples</span>
                        <span class="api-ref-segment-desc">Copy-paste example bodies</span>
                    </button>
                    <?php endif; ?>
                </div>

                <?php if ($hasRequest): ?>
                <div class="api-prompt-panel api-example-panel is-active"
                     role="tabpanel"
                     id="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-panel-request"
                     aria-labelledby="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-tab-request"
                     data-prompt-panel="request">
                    <p class="api-field-ref muted-tip">POST/PATCH JSON body fields and query/path parameters for this resource.</p>
                    <?php $fields = $resource['request_fields']; require __DIR__ . '/api-ref-table.php'; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasResponse): ?>
                <div class="api-prompt-panel api-example-panel<?= !$hasRequest ? ' is-active' : '' ?>"
                     role="tabpanel"
                     id="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-panel-response"
                     aria-labelledby="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-tab-response"
                     data-prompt-panel="response"
                     <?= $hasRequest ? 'hidden' : '' ?>>
                    <?php foreach ($resource['response_sections'] as $section): ?>
                    <div class="api-response-variant">
                        <h5 class="api-ref-subheading"><?= htmlspecialchars($section['title']) ?></h5>
                        <?php if (!empty($section['note'])): ?>
                        <p class="api-variant-note"><?= htmlspecialchars($section['note']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($section['fields'])): ?>
                        <?php $fields = $section['fields']; require __DIR__ . '/api-ref-table.php'; ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasSamples): ?>
                <div class="api-prompt-panel api-example-panel<?= !$hasRequest && !$hasResponse ? ' is-active' : '' ?>"
                     role="tabpanel"
                     id="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-panel-samples"
                     aria-labelledby="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-tab-samples"
                     data-prompt-panel="samples"
                     <?= ($hasRequest || $hasResponse) ? 'hidden' : '' ?>>
                    <p class="api-field-ref muted-tip">Pick a response variant — each tab is a sample JSON body you can copy.</p>
                    <div class="api-prompt-tabs api-tabstrip api-ref-sample-strip" data-api-tabstrip="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-samples">
                        <div class="api-prompt-tablist" role="tablist" aria-label="JSON samples">
                            <?php foreach ($resource['samples'] as $si => $sample): ?>
                            <button type="button"
                                    class="api-prompt-tab<?= $si === 0 ? ' is-active' : '' ?>"
                                    role="tab"
                                    id="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-sample-tab-<?= htmlspecialchars($sample['id'], ENT_QUOTES, 'UTF-8') ?>"
                                    aria-selected="<?= $si === 0 ? 'true' : 'false' ?>"
                                    aria-controls="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-sample-panel-<?= htmlspecialchars($sample['id'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-prompt-tab="<?= htmlspecialchars($sample['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($sample['label']) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ($resource['samples'] as $si => $sample): ?>
                        <div class="api-prompt-panel api-example-panel<?= $si === 0 ? ' is-active' : '' ?>"
                             role="tabpanel"
                             id="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-sample-panel-<?= htmlspecialchars($sample['id'], ENT_QUOTES, 'UTF-8') ?>"
                             aria-labelledby="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-sample-tab-<?= htmlspecialchars($sample['id'], ENT_QUOTES, 'UTF-8') ?>"
                             data-prompt-panel="<?= htmlspecialchars($sample['id'], ENT_QUOTES, 'UTF-8') ?>"
                             <?= $si === 0 ? '' : 'hidden' ?>>
                            <pre class="api-code-block api-json-block" id="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-json-<?= htmlspecialchars($sample['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sample['json']) ?></pre>
                            <button type="button" class="btn btn-secondary api-copy-btn" data-copy-from="<?= htmlspecialchars($innerStripId, ENT_QUOTES, 'UTF-8') ?>-json-<?= htmlspecialchars($sample['id'], ENT_QUOTES, 'UTF-8') ?>">Copy JSON</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            </div><!-- .api-ref-detail -->
        </div>
        <?php endforeach; ?>
    </div>
</section>
