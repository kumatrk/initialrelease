<?php
/**
 * Campaign Performance table body (desktop + mobile).
 * Expects: $campaignStats, $campaignStatsTotal, $campaignsPage, $campaignsPerPage,
 * $dateFrom, $dateTo, $allowedStatuses, $userCurrency
 */
use SimpleKuma\Utils\Formatter;

$campaignStats = $campaignStats ?? [];
$campaignStatsTotal = (int)($campaignStatsTotal ?? 0);
$campaignsPage = (int)($campaignsPage ?? 1);
$campaignsPerPage = (int)($campaignsPerPage ?? 25);

$groupedCampaigns = [];
$ungroupedCampaigns = [];

foreach ($campaignStats as $camp) {
    if (!empty($camp['campaign_group_name'])) {
        if (!isset($groupedCampaigns[$camp['campaign_group_name']])) {
            $groupedCampaigns[$camp['campaign_group_name']] = [];
        }
        $groupedCampaigns[$camp['campaign_group_name']][] = $camp;
    } else {
        $ungroupedCampaigns[] = $camp;
    }
}

$groupTotals = [];
foreach ($groupedCampaigns as $groupName => $campaigns) {
    $groupViews = 0;
    $groupLpClicks = 0;
    $groupDirectClicks = 0;
    $groupConversions = 0;
    $groupCost = 0.0;
    $groupRevenue = 0.0;
    $groupInvalidClicks = 0;
    foreach ($campaigns as $camp) {
        $groupViews += (int)$camp['views'];
        $groupLpClicks += (int)$camp['lp_clicks'];
        $groupDirectClicks += (int)$camp['direct_clicks'];
        $groupConversions += (int)$camp['conversions'];
        $groupCost += (float)$camp['cost'];
        $groupRevenue += (float)$camp['revenue'];
        $groupInvalidClicks += (int)($camp['invalid_clicks'] ?? 0);
    }
    $groupProfit = $groupRevenue - $groupCost;
    $groupRoi = $groupCost > 0 ? (($groupRevenue - $groupCost) / $groupCost) * 100 : 0;
    $groupEpc = $groupViews > 0 ? $groupRevenue / $groupViews : 0;
    $groupCr = $groupViews > 0 ? ($groupConversions / $groupViews) * 100 : 0;
    $groupCtr = $groupViews > 0 ? ($groupLpClicks / $groupViews) * 100 : 0;
    $groupTotals[$groupName] = [
        'views' => $groupViews,
        'lp_clicks' => $groupLpClicks,
        'direct_clicks' => $groupDirectClicks,
        'conversions' => $groupConversions,
        'cost' => $groupCost,
        'revenue' => $groupRevenue,
        'profit' => $groupProfit,
        'roi' => $groupRoi,
        'epc' => $groupEpc,
        'cr' => $groupCr,
        'ctr' => $groupCtr,
        'invalid_clicks' => $groupInvalidClicks,
    ];
}
?>

        <?php if ($campaignStatsTotal === 0): ?>
            <div style="text-align: center; padding: 40px; color: #999;">
                <div style="font-size: 48px; margin-bottom: 16px;">📭</div>
                <p>No campaigns yet. Create your first campaign to start tracking!</p>
            </div>
        <?php else: ?>
            <?php
            $campaignsTotalPages = max(1, (int)ceil($campaignStatsTotal / max(1, $campaignsPerPage)));
            $statusQuery = '';
            if ($allowedStatuses !== null) {
                foreach ($allowedStatuses as $st) {
                    $statusQuery .= '&status_filter[]=' . urlencode($st);
                }
            }
            $dashBaseQs = 'page=dashboard&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) . $statusQuery;
            ?>
            <?php if ($campaignsTotalPages > 1): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; font-size:13px; color:#666;">
                <span>Showing <?= count($campaignStats) ?> of <?= (int)$campaignStatsTotal ?> campaigns (page <?= (int)$campaignsPage ?>/<?= $campaignsTotalPages ?>)</span>
                <span style="display:flex; gap:8px;">
                    <?php if ($campaignsPage > 1): ?>
                        <a class="btn btn-outline" style="padding:4px 10px; font-size:12px;" href="?<?= $dashBaseQs ?>&campaigns_page=<?= $campaignsPage - 1 ?>" data-dashboard-campaigns-page="<?= $campaignsPage - 1 ?>">Previous</a>
                    <?php endif; ?>
                    <?php if ($campaignsPage < $campaignsTotalPages): ?>
                        <a class="btn btn-outline" style="padding:4px 10px; font-size:12px;" href="?<?= $dashBaseQs ?>&campaigns_page=<?= $campaignsPage + 1 ?>" data-dashboard-campaigns-page="<?= $campaignsPage + 1 ?>">Next</a>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <!-- Desktop Table View (hidden on mobile) -->
            <div class="table-wrapper desktop-only">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Traffic Source</th>
                            <th>Views</th>
                            <th>Clicks</th>
                            <th>Direct</th>
                            <th>Conv</th>
                            <th>Cost</th>
                            <th>Revenue</th>
                            <th>Profit</th>
                            <th>ROI</th>
                            <th>EPC</th>
                            <th>CTR</th>
                            <th>CR</th>
                            <th style="width: 90px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Render grouped campaigns
                        foreach ($groupedCampaigns as $groupName => $campaigns):
                            $groupTotal = $groupTotals[$groupName];
                        ?>
                            <tr class="group-header" onclick="toggleDashboardGroup('<?= md5($groupName) ?>')" style="cursor: pointer; background: #f9f9f9; font-weight: 600;">
                                <td style="color: #3d5a26; padding: 12px;">
                                    <span id="toggle-<?= md5($groupName) ?>">▶</span>
                                    📁 <?= htmlspecialchars($groupName) ?>
                                    <span style="color: #999; font-weight: normal; margin-left: 8px;">(<?= count($campaigns) ?> campaigns)</span>
                                </td>
                                <td></td>
                                <td><?= number_format($groupTotal['views']) ?></td>
                                <td><?= number_format($groupTotal['lp_clicks']) ?></td>
                                <td><?= number_format($groupTotal['direct_clicks']) ?></td>
                                <td><?= number_format($groupTotal['conversions']) ?></td>
                                <td>$<?= number_format($groupTotal['cost'], 2) ?></td>
                                <td style="color: #28a745; font-weight: 600;">$<?= number_format($groupTotal['revenue'], 2) ?></td>
                                <td style="color: <?= $groupTotal['profit'] >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 600;">
                                    $<?= number_format($groupTotal['profit'], 2) ?>
                                </td>
                                <td style="color: <?= $groupTotal['roi'] >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 600;">
                                    <?= number_format($groupTotal['roi'], 1) ?>%
                                </td>
                                <td>$<?= number_format($groupTotal['epc'], 2) ?></td>
                                <td><?= number_format($groupTotal['ctr'], 2) ?>%</td>
                                <td><?= number_format($groupTotal['cr'], 2) ?>%</td>
                                <td></td>
                            </tr>
                            <?php foreach ($campaigns as $idx => $row): ?>
                                <?php
                                $cViews = (int)$row['views'];
                                $cLpClicks = (int)$row['lp_clicks'];
                                $cDirectClicks = (int)$row['direct_clicks'];
                                $cRevenue = (float)$row['revenue'];
                                $cCost = (float)$row['cost'];
                                $cProfit = $cRevenue - $cCost;
                                $cRoi = $cCost > 0 ? (($cRevenue - $cCost) / $cCost) * 100 : 0;
                                $cEpc = $cViews > 0 ? $cRevenue / $cViews : 0;
                                $cCtr = $cViews > 0 ? ($cLpClicks / $cViews) * 100 : 0;
                                $cCr = $cViews > 0 ? ((int)$row['conversions'] / $cViews) * 100 : 0;
                                $groupId = md5($groupName);
                                $isAutoDetect = empty($row['traffic_source_id']) || (int)$row['traffic_source_id'] === 0;
                                ?>
                            <tr id="group-<?= $groupId ?>-row-<?= $row['id'] ?>" class="group-campaign-row group-<?= $groupId ?>" style="background: #fafafa; display: none;">
                                <td style="padding-left: 40px;">
                                    <strong><?= htmlspecialchars($row['name']) ?></strong>
                                    <span class="badge badge-<?= $row['status'] === 'active' ? 'success' : 'warning' ?>" style="margin-left: 8px; font-size: 10px;">
                                        <?= $row['status'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isAutoDetect): ?>
                                        <span style="color: #558b2f; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;">
                                            <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto Detected" style="width: 20px; height: 20px; object-fit: contain; vertical-align: middle;">
                                            Auto Detected
                                        </span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($row['traffic_source_name'] ?? 'N/A') ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($cViews) ?></td>
                                <td><?= number_format($cLpClicks) ?></td>
                                <td><?= number_format($cDirectClicks) ?></td>
                                <td><?= number_format($row['conversions']) ?></td>
                                <td><?= Formatter::formatCurrency($cCost, $userCurrency) ?></td>
                                <td style="color: #28a745; font-weight: 600;"><?= Formatter::formatCurrency($cRevenue, $userCurrency) ?></td>
                                <td style="color: <?= $cProfit >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 600;">
                                    <?= Formatter::formatCurrency($cProfit, $userCurrency) ?>
                                </td>
                                <td style="color: <?= $cRoi >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 600;">
                                    <?= number_format($cRoi, 1) ?>%
                                </td>
                                <td><?= Formatter::formatCurrency($cEpc, $userCurrency) ?></td>
                                <td><?= number_format($cCtr, 2) ?>%</td>
                                <td><?= number_format($cCr, 2) ?>%</td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <?php if ($isAutoDetect && isset($row['traffic_source_stats']) && count($row['traffic_source_stats']) > 0): ?>
                                            <button onclick="toggleTrafficSources('campaign-<?= $row['id'] ?>')" 
                                                    style="width: 32px; height: 32px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; font-size: 14px;"
                                                    title="Toggle Traffic Sources"
                                                    id="toggle-btn-campaign-<?= $row['id'] ?>"
                                                    onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                                    onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                                ▶
                                            </button>
                                        <?php endif; ?>
                                        <a href="?page=campaign-stats&campaign_id=<?= $row['id'] ?>" 
                                           style="width: 32px; height: 32px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none; font-size: 14px;"
                                           title="View Stats"
                                           onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                           onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                            📊
                                        </a>
                                        <a href="?page=campaign-list&action=edit&id=<?= $row['id'] ?>" 
                                           style="width: 32px; height: 32px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none; font-size: 14px;"
                                           title="Edit Campaign"
                                           onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                           onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                            ✏️
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                            // Show invalid/filtered clicks row if any exist
                            $invalidClicks = (int)($row['invalid_clicks'] ?? 0);
                            if ($invalidClicks > 0): ?>
                                <tr id="group-<?= $groupId ?>-row-<?= $row['id'] ?>-invalid" class="group-campaign-row group-<?= $groupId ?>" style="background: #fff3cd; display: none; border-left: 3px solid #ffc107;">
                                    <td style="padding-left: 60px; color: #856404; font-size: 12px; font-style: italic;">
                                        <span style="opacity: 0.7;">⚠️</span> Invalid/Filtered Clicks (FB Test Clicks)
                                    </td>
                                    <td style="color: #856404; font-size: 12px;">—</td>
                                    <td style="color: #856404; font-weight: 600;"><?= number_format($invalidClicks) ?></td>
                                    <td style="color: #856404;">—</td>
                                    <td style="color: #856404;">—</td>
                                    <td style="color: #856404;">—</td>
                                    <td style="color: #856404;">—</td>
                                    <td style="color: #856404;">—</td>
                                    <td style="color: #856404;">—</td>
                                    <td style="color: #856404;">—</td>
                                    <td style="color: #856404;">—</td>
                                    <td style="color: #856404;">—</td>
                                    <td style="color: #856404;">—</td>
                                    <td style="color: #856404; font-size: 11px;" title="These clicks were excluded because they lack valid ad_id and adset_id (Meta approval team test clicks)">
                                        ℹ️
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($isAutoDetect && isset($row['traffic_source_stats']) && count($row['traffic_source_stats']) > 0): ?>
                                <?php foreach ($row['traffic_source_stats'] as $tsStat): ?>
                                    <?php
                                    $tsViews = (int)$tsStat['views'];
                                    $tsLpClicks = (int)$tsStat['lp_clicks'];
                                    $tsDirectClicks = (int)$tsStat['direct_clicks'];
                                    $tsRevenue = (float)$tsStat['revenue'];
                                    $tsCost = (float)$tsStat['cost'];
                                    $tsProfit = $tsRevenue - $tsCost;
                                    $tsRoi = $tsCost > 0 ? (($tsRevenue - $tsCost) / $tsCost) * 100 : 0;
                                    $tsEpc = $tsViews > 0 ? $tsRevenue / $tsViews : 0;
                                    $tsCtr = $tsViews > 0 ? ($tsLpClicks / $tsViews) * 100 : 0;
                                    $tsCr = $tsViews > 0 ? ((int)$tsStat['conversions'] / $tsViews) * 100 : 0;
                                    ?>
                                    <tr id="group-<?= $groupId ?>-row-<?= $row['id'] ?>-ts-<?= $tsStat['traffic_source_id'] ?>" class="group-campaign-row group-<?= $groupId ?> traffic-source-row campaign-<?= $row['id'] ?>" style="background: #f5f5f5; display: none;">
                                        <td style="padding-left: 80px; color: #999; font-size: 12px;">
                                            <span style="opacity: 0.5;">└─</span>
                                        </td>
                                        <td style="padding-left: 20px; color: #666; font-size: 13px;">
                                            <?= htmlspecialchars($tsStat['traffic_source_name'] ?? 'Unknown') ?>
                                        </td>
                                        <td style="color: #666;"><?= number_format($tsViews) ?></td>
                                        <td style="color: #666;"><?= number_format($tsLpClicks) ?></td>
                                        <td style="color: #666;"><?= number_format($tsDirectClicks) ?></td>
                                        <td style="color: #666;"><?= number_format($tsStat['conversions']) ?></td>
                                        <td style="color: #666;">$<?= number_format($tsCost, 2) ?></td>
                                        <td style="color: <?= $tsProfit >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 500;">$<?= number_format($tsRevenue, 2) ?></td>
                                        <td style="color: <?= $tsProfit >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 500;">
                                            $<?= number_format($tsProfit, 2) ?>
                                        </td>
                                        <td style="color: <?= $tsRoi >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 500;">
                                            <?= number_format($tsRoi, 1) ?>%
                                        </td>
                                        <td style="color: #666;">$<?= number_format($tsEpc, 2) ?></td>
                                        <td style="color: #666;"><?= number_format($tsCtr, 2) ?>%</td>
                                        <td style="color: #666;"><?= number_format($tsCr, 2) ?>%</td>
                                        <td></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        
                        <?php
                        // Render ungrouped campaigns
                        foreach ($ungroupedCampaigns as $row):
                            $cViews = (int)$row['views'];
                            $cLpClicks = (int)$row['lp_clicks'];
                            $cDirectClicks = (int)$row['direct_clicks'];
                            $cRevenue = (float)$row['revenue'];
                            $cCost = (float)$row['cost'];
                            $cProfit = $cRevenue - $cCost;
                            $cRoi = $cCost > 0 ? (($cRevenue - $cCost) / $cCost) * 100 : 0;
                            $cEpc = $cViews > 0 ? $cRevenue / $cViews : 0;
                            $cCtr = $cViews > 0 ? ($cLpClicks / $cViews) * 100 : 0;
                            $cCr = $cViews > 0 ? ((int)$row['conversions'] / $cViews) * 100 : 0;
                            $isAutoDetect = empty($row['traffic_source_id']) || (int)$row['traffic_source_id'] === 0;
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($row['name']) ?></strong>
                                <span class="badge badge-<?= $row['status'] === 'active' ? 'success' : 'warning' ?>" style="margin-left: 8px; font-size: 10px;">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isAutoDetect): ?>
                                    <span style="color: #558b2f; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;">
                                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto Detected" style="width: 20px; height: 20px; object-fit: contain; vertical-align: middle;">
                                        Auto Detected
                                    </span>
                                <?php else: ?>
                                    <?= htmlspecialchars($row['traffic_source_name'] ?? 'N/A') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($cViews) ?></td>
                            <td><?= number_format($cLpClicks) ?></td>
                            <td><?= number_format($cDirectClicks) ?></td>
                            <td><?= number_format($row['conversions']) ?></td>
                            <td><?= Formatter::formatCurrency($cCost, $userCurrency) ?></td>
                            <td style="color: #28a745; font-weight: 600;"><?= Formatter::formatCurrency($cRevenue, $userCurrency) ?></td>
                            <td style="color: <?= $cProfit >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 600;">
                                <?= Formatter::formatCurrency($cProfit, $userCurrency) ?>
                            </td>
                            <td style="color: <?= $cRoi >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 600;">
                                <?= number_format($cRoi, 1) ?>%
                            </td>
                            <td><?= Formatter::formatCurrency($cEpc, $userCurrency) ?></td>
                            <td><?= number_format($cCtr, 2) ?>%</td>
                            <td><?= number_format($cCr, 2) ?>%</td>
                            <td>
                                <div style="display: flex; gap: 6px;">
                                    <?php if ($isAutoDetect && isset($row['traffic_source_stats']) && count($row['traffic_source_stats']) > 0): ?>
                                        <button onclick="toggleTrafficSources('campaign-<?= $row['id'] ?>')" 
                                                style="width: 32px; height: 32px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; font-size: 14px;"
                                                title="Toggle Traffic Sources"
                                                id="toggle-btn-campaign-<?= $row['id'] ?>"
                                                onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                                onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                            ▶
                                        </button>
                                    <?php endif; ?>
                                    <a href="?page=campaign-stats&campaign_id=<?= $row['id'] ?>" 
                                       style="width: 32px; height: 32px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none; font-size: 14px;"
                                       title="View Stats"
                                       onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                       onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                        📊
                                    </a>
                                    <a href="?page=campaign-list&action=edit&id=<?= $row['id'] ?>" 
                                       style="width: 32px; height: 32px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none; font-size: 14px;"
                                       title="Edit Campaign"
                                       onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                       onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                        ✏️
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php if ($isAutoDetect && !empty($row['traffic_source_stats'])): ?>
                            <?php foreach ($row['traffic_source_stats'] as $tsStat): ?>
                                <?php
                                $tsViews = (int)$tsStat['views'];
                                $tsLpClicks = (int)$tsStat['lp_clicks'];
                                $tsDirectClicks = (int)$tsStat['direct_clicks'];
                                $tsRevenue = (float)$tsStat['revenue'];
                                $tsCost = (float)$tsStat['cost'];
                                $tsProfit = $tsRevenue - $tsCost;
                                $tsRoi = $tsCost > 0 ? (($tsRevenue - $tsCost) / $tsCost) * 100 : 0;
                                $tsEpc = $tsViews > 0 ? $tsRevenue / $tsViews : 0;
                                $tsCtr = $tsViews > 0 ? ($tsLpClicks / $tsViews) * 100 : 0;
                                $tsCr = $tsViews > 0 ? ((int)$tsStat['conversions'] / $tsViews) * 100 : 0;
                                ?>
                                <tr class="traffic-source-row campaign-<?= $row['id'] ?>" style="background: #f5f5f5; display: none;">
                                    <td style="padding-left: 40px; color: #999; font-size: 12px;">
                                        <span style="opacity: 0.5;">└─</span>
                                    </td>
                                    <td style="padding-left: 20px; color: #666; font-size: 13px;">
                                        <?= htmlspecialchars($tsStat['traffic_source_name'] ?? 'Unknown') ?>
                                    </td>
                                    <td style="color: #666;"><?= number_format($tsViews) ?></td>
                                    <td style="color: #666;"><?= number_format($tsLpClicks) ?></td>
                                    <td style="color: #666;"><?= number_format($tsDirectClicks) ?></td>
                                    <td style="color: #666;"><?= number_format($tsStat['conversions']) ?></td>
                                    <td style="color: #666;">$<?= number_format($tsCost, 2) ?></td>
                                    <td style="color: <?= $tsProfit >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 500;">$<?= number_format($tsRevenue, 2) ?></td>
                                    <td style="color: <?= $tsProfit >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 500;">
                                        $<?= number_format($tsProfit, 2) ?>
                                    </td>
                                    <td style="color: <?= $tsRoi >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 500;">
                                        <?= number_format($tsRoi, 1) ?>%
                                    </td>
                                    <td style="color: #666;">$<?= number_format($tsEpc, 2) ?></td>
                                    <td style="color: #666;"><?= number_format($tsCtr, 2) ?>%</td>
                                    <td style="color: #666;"><?= number_format($tsCr, 2) ?>%</td>
                                    <td></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Mobile Card View (hidden on desktop) -->
            <div class="mobile-campaign-cards mobile-only">
                <?php
                // Render grouped campaigns as cards
                foreach ($groupedCampaigns as $groupName => $campaigns):
                    $groupTotal = $groupTotals[$groupName];
                ?>
                    <div class="mobile-group-card" style="margin-bottom: var(--spacing-md);">
                        <div class="mobile-group-header" onclick="toggleMobileGroup('<?= md5($groupName) ?>')" style="background: var(--color-forest); color: var(--color-cream); padding: var(--spacing-md); border-radius: var(--radius-md) var(--radius-md) 0 0; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="font-size: var(--font-size-base);">📁 <?= htmlspecialchars($groupName) ?></strong>
                                <div style="font-size: var(--font-size-xs); opacity: 0.9; margin-top: 4px;"><?= count($campaigns) ?> campaigns</div>
                            </div>
                            <span id="mobile-toggle-<?= md5($groupName) ?>" style="font-size: 20px;">▶</span>
                        </div>
                        <div class="mobile-group-summary" style="background: var(--color-cream); padding: var(--spacing-sm) var(--spacing-md); border-left: 3px solid var(--color-forest); display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--spacing-xs); font-size: var(--font-size-xs);">
                            <div><strong>Views:</strong> <?= number_format($groupTotal['views']) ?></div>
                            <div><strong>Clicks:</strong> <?= number_format($groupTotal['lp_clicks']) ?></div>
                            <div><strong>Conv:</strong> <?= number_format($groupTotal['conversions']) ?></div>
                            <div><strong>Revenue:</strong> <span style="color: #28a745;">$<?= number_format($groupTotal['revenue'], 2) ?></span></div>
                            <div><strong>Cost:</strong> $<?= number_format($groupTotal['cost'], 2) ?></div>
                            <div><strong>Profit:</strong> <span style="color: <?= $groupTotal['profit'] >= 0 ? '#28a745' : '#d32f2f' ?>;">$<?= number_format($groupTotal['profit'], 2) ?></span></div>
                            <div><strong>ROI:</strong> <span style="color: <?= $groupTotal['roi'] >= 0 ? '#28a745' : '#d32f2f' ?>;"><?= number_format($groupTotal['roi'], 1) ?>%</span></div>
                            <div><strong>EPC:</strong> $<?= number_format($groupTotal['epc'], 2) ?></div>
                        </div>
                        <div id="mobile-group-<?= md5($groupName) ?>" class="mobile-group-campaigns" style="display: none;">
                            <?php foreach ($campaigns as $row): ?>
                                <?php
                                $cViews = (int)$row['views'];
                                $cLpClicks = (int)$row['lp_clicks'];
                                $cDirectClicks = (int)$row['direct_clicks'];
                                $cRevenue = (float)$row['revenue'];
                                $cCost = (float)$row['cost'];
                                $cProfit = $cRevenue - $cCost;
                                $cRoi = $cCost > 0 ? (($cRevenue - $cCost) / $cCost) * 100 : 0;
                                $cEpc = $cViews > 0 ? $cRevenue / $cViews : 0;
                                $cCtr = $cViews > 0 ? ($cLpClicks / $cViews) * 100 : 0;
                                $cCr = $cViews > 0 ? ((int)$row['conversions'] / $cViews) * 100 : 0;
                                $isAutoDetect = empty($row['traffic_source_id']) || (int)$row['traffic_source_id'] === 0;
                                ?>
                                <div class="mobile-campaign-card" style="background: var(--color-white); border: 1px solid var(--color-gray-200); border-top: none; padding: var(--spacing-md);">
                                    <!-- Campaign Header -->
                                    <div style="margin-bottom: var(--spacing-md); padding-bottom: var(--spacing-sm); border-bottom: 2px solid var(--color-gray-200);">
                                        <strong style="font-size: 15px; color: var(--color-forest); display: block; margin-bottom: 6px; font-weight: 600;"><?= htmlspecialchars($row['name']) ?></strong>
                                        <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                                            <span class="badge badge-<?= $row['status'] === 'active' ? 'success' : 'warning' ?>" style="font-size: 11px; padding: 4px 8px; font-weight: 500;"><?= strtoupper($row['status']) ?></span>
                                            <?php if ($isAutoDetect): ?>
                                                <span style="color: #558b2f; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; font-weight: 500;">
                                                    <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto" style="width: 16px; height: 16px;"> Auto
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--color-gray-600); font-size: 12px; font-weight: 500;"><?= htmlspecialchars($row['traffic_source_name'] ?? 'N/A') ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Key Metrics - Highlighted -->
                                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: var(--spacing-md); padding: 8px 4px; background: <?= $cRoi >= 0 ? 'linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%)' : 'linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%)' ?>; border-radius: var(--radius-sm); border: 2px solid <?= $cRoi >= 0 ? '#4caf50' : '#f44336' ?>;">
                                        <div style="text-align: center;">
                                            <div style="font-size: 10px; color: #555; margin-bottom: 3px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Revenue</div>
                                            <div style="font-size: 22px; color: #28a745; font-weight: 700; line-height: 1.1;"><?= Formatter::formatCurrency($cRevenue, $userCurrency) ?></div>
                                        </div>
                                        <div style="text-align: center;">
                                            <div style="font-size: 10px; color: #555; margin-bottom: 3px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Profit</div>
                                            <div style="font-size: 22px; color: <?= $cProfit >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 700; line-height: 1.1;"><?= Formatter::formatCurrency($cProfit, $userCurrency) ?></div>
                                        </div>
                                        <div style="text-align: center;">
                                            <div style="font-size: 10px; color: #555; margin-bottom: 3px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">ROI</div>
                                            <div style="font-size: 22px; color: <?= $cRoi >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 700; line-height: 1.1;"><?= number_format($cRoi, 1) ?>%</div>
                                        </div>
                                    </div>
                                    
                                    <!-- Detailed Stats Grid -->
                                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 12px; margin-bottom: var(--spacing-md); padding: 18px 10px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: var(--radius-sm); border: 1px solid #e0e0e0;">
                                        <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                            <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">Views</strong>
                                            <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($cViews) ?></span>
                                        </div>
                                        <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                            <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">Clicks</strong>
                                            <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($cLpClicks) ?></span>
                                        </div>
                                        <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                            <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">Direct</strong>
                                            <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($cDirectClicks) ?></span>
                                        </div>
                                        <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                            <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">Conv</strong>
                                            <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($row['conversions']) ?></span>
                                        </div>
                                        <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                            <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">Cost</strong>
                                            <span style="color: #d32f2f; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= Formatter::formatCurrency($cCost, $userCurrency) ?></span>
                                        </div>
                                        <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                            <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">EPC</strong>
                                            <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= Formatter::formatCurrency($cEpc, $userCurrency) ?></span>
                                        </div>
                                        <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                            <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">CTR</strong>
                                            <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($cCtr, 2) ?>%</span>
                                        </div>
                                        <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                            <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">CR</strong>
                                            <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($cCr, 2) ?>%</span>
                                        </div>
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: var(--spacing-md); padding-top: var(--spacing-md); border-top: 1px solid var(--color-gray-200);">
                                        <a href="?page=campaign-stats&campaign_id=<?= $row['id'] ?>" style="flex: 1 1 calc(33.333% - 6px); min-width: calc(33.333% - 6px); padding: 12px 10px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; text-decoration: none; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent; font-weight: 500; transition: all 0.2s;" onmouseover="this.style.background='#f0f0f0'; this.style.borderColor='#4caf50'; this.style.color='#4caf50';" onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                            📊 Stats
                                        </a>
                                        <a href="?page=campaign-list&action=edit&id=<?= $row['id'] ?>" style="flex: 1 1 calc(50% - 6px); min-width: calc(50% - 6px); padding: 12px 10px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; text-decoration: none; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent; font-weight: 500; transition: all 0.2s;" onmouseover="this.style.background='#fff3e0'; this.style.borderColor='#ff9800'; this.style.color='#ff9800';" onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                            ✏️ Edit
                                        </a>
                                    </div>
                                    <?php if ($isAutoDetect && isset($row['traffic_source_stats']) && count($row['traffic_source_stats']) > 0): ?>
                                        <button onclick="toggleMobileTrafficSources('mobile-campaign-<?= $row['id'] ?>')" style="width: 100%; margin-top: var(--spacing-sm); padding: var(--spacing-xs); background: var(--color-cream); border: 1px solid var(--color-gray-300); border-radius: var(--radius-sm); cursor: pointer; font-size: var(--font-size-xs); color: var(--color-gray-600);">
                                            <span id="mobile-ts-toggle-<?= $row['id'] ?>">▶</span> Show Traffic Sources (<?= count($row['traffic_source_stats']) ?>)
                                        </button>
                                        <div id="mobile-campaign-<?= $row['id'] ?>" style="display: none; margin-top: var(--spacing-sm); padding-top: var(--spacing-sm); border-top: 1px solid var(--color-gray-200);">
                                            <?php foreach ($row['traffic_source_stats'] as $tsStat): ?>
                                                <?php
                                                $tsViews = (int)$tsStat['views'];
                                                $tsLpClicks = (int)$tsStat['lp_clicks'];
                                                $tsDirectClicks = (int)$tsStat['direct_clicks'];
                                                $tsRevenue = (float)$tsStat['revenue'];
                                                $tsCost = (float)$tsStat['cost'];
                                                $tsProfit = $tsRevenue - $tsCost;
                                                $tsRoi = $tsCost > 0 ? (($tsRevenue - $tsCost) / $tsCost) * 100 : 0;
                                                $tsEpc = $tsViews > 0 ? $tsRevenue / $tsViews : 0;
                                                $tsCtr = $tsViews > 0 ? ($tsLpClicks / $tsViews) * 100 : 0;
                                                $tsCr = $tsViews > 0 ? ((int)$tsStat['conversions'] / $tsViews) * 100 : 0;
                                                ?>
                                                <div style="background: var(--color-cream); padding: var(--spacing-sm); border-radius: var(--radius-sm); margin-bottom: var(--spacing-xs); border-left: 3px solid var(--color-bark);">
                                                    <div style="font-weight: 600; color: var(--color-forest); margin-bottom: 4px; font-size: var(--font-size-sm);"><?= htmlspecialchars($tsStat['traffic_source_name'] ?? 'Unknown') ?></div>
                                                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px; font-size: 11px;">
                                                        <div>Views: <?= number_format($tsViews) ?></div>
                                                        <div>Clicks: <?= number_format($tsLpClicks) ?></div>
                                                        <div>Conv: <?= number_format($tsStat['conversions']) ?></div>
                                                        <div>Revenue: <span style="color: #28a745;">$<?= number_format($tsRevenue, 2) ?></span></div>
                                                        <div>Cost: $<?= number_format($tsCost, 2) ?></div>
                                                        <div>Profit: <span style="color: <?= $tsProfit >= 0 ? '#28a745' : '#d32f2f' ?>;">$<?= number_format($tsProfit, 2) ?></span></div>
                                                        <div>ROI: <span style="color: <?= $tsRoi >= 0 ? '#28a745' : '#d32f2f' ?>;"><?= number_format($tsRoi, 1) ?>%</span></div>
                                                        <div>EPC: $<?= number_format($tsEpc, 2) ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php
                // Render ungrouped campaigns as cards
                foreach ($ungroupedCampaigns as $row):
                    $cViews = (int)$row['views'];
                    $cLpClicks = (int)$row['lp_clicks'];
                    $cDirectClicks = (int)$row['direct_clicks'];
                    $cRevenue = (float)$row['revenue'];
                    $cCost = (float)$row['cost'];
                    $cProfit = $cRevenue - $cCost;
                    $cRoi = $cCost > 0 ? (($cRevenue - $cCost) / $cCost) * 100 : 0;
                    $cEpc = $cViews > 0 ? $cRevenue / $cViews : 0;
                    $cCtr = $cViews > 0 ? ($cLpClicks / $cViews) * 100 : 0;
                    $cCr = $cViews > 0 ? ((int)$row['conversions'] / $cViews) * 100 : 0;
                    $isAutoDetect = empty($row['traffic_source_id']) || (int)$row['traffic_source_id'] === 0;
                ?>
                    <div class="mobile-campaign-card" style="background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); padding: var(--spacing-md); margin-bottom: var(--spacing-md); box-shadow: 0 2px 4px rgba(0,0,0,0.08);">
                        <!-- Campaign Header -->
                        <div style="margin-bottom: var(--spacing-md); padding-bottom: var(--spacing-sm); border-bottom: 2px solid var(--color-gray-200);">
                            <strong style="font-size: 15px; color: var(--color-forest); display: block; margin-bottom: 6px; font-weight: 600;"><?= htmlspecialchars($row['name']) ?></strong>
                            <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                                <span class="badge badge-<?= $row['status'] === 'active' ? 'success' : 'warning' ?>" style="font-size: 11px; padding: 4px 8px; font-weight: 500;"><?= strtoupper($row['status']) ?></span>
                                <?php if ($isAutoDetect): ?>
                                    <span style="color: #558b2f; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; font-weight: 500;">
                                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto" style="width: 16px; height: 16px;"> Auto
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--color-gray-600); font-size: 12px; font-weight: 500;"><?= htmlspecialchars($row['traffic_source_name'] ?? 'N/A') ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Key Metrics - Highlighted -->
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: var(--spacing-md); padding: 8px 4px; background: <?= $cRoi >= 0 ? 'linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%)' : 'linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%)' ?>; border-radius: var(--radius-sm); border: 2px solid <?= $cRoi >= 0 ? '#4caf50' : '#f44336' ?>;">
                            <div style="text-align: center;">
                                <div style="font-size: 10px; color: #555; margin-bottom: 3px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Revenue</div>
                                <div style="font-size: 22px; color: #28a745; font-weight: 700; line-height: 1.1;"><?= Formatter::formatCurrency($cRevenue, $userCurrency) ?></div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 10px; color: #555; margin-bottom: 3px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Profit</div>
                                <div style="font-size: 22px; color: <?= $cProfit >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 700; line-height: 1.1;"><?= Formatter::formatCurrency($cProfit, $userCurrency) ?></div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 10px; color: #555; margin-bottom: 3px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">ROI</div>
                                <div style="font-size: 22px; color: <?= $cRoi >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 700; line-height: 1.1;"><?= number_format($cRoi, 1) ?>%</div>
                            </div>
                        </div>
                        
                        <!-- Detailed Stats Grid -->
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 12px; margin-bottom: var(--spacing-md); padding: 18px 10px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: var(--radius-sm); border: 1px solid #e0e0e0;">
                            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">Views</strong>
                                <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($cViews) ?></span>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">Clicks</strong>
                                <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($cLpClicks) ?></span>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">Direct</strong>
                                <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($cDirectClicks) ?></span>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">Conv</strong>
                                <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($row['conversions']) ?></span>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">Cost</strong>
                                <span style="color: #d32f2f; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= Formatter::formatCurrency($cCost, $userCurrency) ?></span>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">EPC</strong>
                                <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= Formatter::formatCurrency($cEpc, $userCurrency) ?></span>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">CTR</strong>
                                <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($cCtr, 2) ?>%</span>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 10px; background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                                <strong style="color: #555; font-size: 13px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 100%;">CR</strong>
                                <span style="color: #333; font-size: 18px; font-weight: 700; line-height: 1.2;"><?= number_format($cCr, 2) ?>%</span>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: var(--spacing-md); padding-top: var(--spacing-md); border-top: 1px solid var(--color-gray-200);">
                            <a href="?page=campaign-stats&campaign_id=<?= $row['id'] ?>" style="flex: 1 1 calc(33.333% - 6px); min-width: calc(33.333% - 6px); padding: 12px 10px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; text-decoration: none; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent; font-weight: 500; transition: all 0.2s;" onmouseover="this.style.background='#f0f0f0'; this.style.borderColor='#4caf50'; this.style.color='#4caf50';" onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                📊 Stats
                            </a>
                            <a href="?page=campaign-list&action=edit&id=<?= $row['id'] ?>" style="flex: 1 1 calc(50% - 6px); min-width: calc(50% - 6px); padding: 12px 10px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; text-decoration: none; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent; font-weight: 500; transition: all 0.2s;" onmouseover="this.style.background='#fff3e0'; this.style.borderColor='#ff9800'; this.style.color='#ff9800';" onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                ✏️ Edit
                            </a>
                        </div>
                        <?php if ($isAutoDetect && isset($row['traffic_source_stats']) && count($row['traffic_source_stats']) > 0): ?>
                            <button onclick="toggleMobileTrafficSources('mobile-campaign-<?= $row['id'] ?>')" style="width: 100%; margin-top: var(--spacing-sm); padding: var(--spacing-xs); background: var(--color-cream); border: 1px solid var(--color-gray-300); border-radius: var(--radius-sm); cursor: pointer; font-size: var(--font-size-xs); color: var(--color-gray-600);">
                                <span id="mobile-ts-toggle-<?= $row['id'] ?>">▶</span> Show Traffic Sources (<?= count($row['traffic_source_stats']) ?>)
                            </button>
                            <div id="mobile-campaign-<?= $row['id'] ?>" style="display: none; margin-top: var(--spacing-sm); padding-top: var(--spacing-sm); border-top: 1px solid var(--color-gray-200);">
                                <?php foreach ($row['traffic_source_stats'] as $tsStat): ?>
                                    <?php
                                    $tsViews = (int)$tsStat['views'];
                                    $tsLpClicks = (int)$tsStat['lp_clicks'];
                                    $tsDirectClicks = (int)$tsStat['direct_clicks'];
                                    $tsRevenue = (float)$tsStat['revenue'];
                                    $tsCost = (float)$tsStat['cost'];
                                    $tsProfit = $tsRevenue - $tsCost;
                                    $tsRoi = $tsCost > 0 ? (($tsRevenue - $tsCost) / $tsCost) * 100 : 0;
                                    $tsEpc = $tsViews > 0 ? $tsRevenue / $tsViews : 0;
                                    $tsCtr = $tsViews > 0 ? ($tsLpClicks / $tsViews) * 100 : 0;
                                    $tsCr = $tsViews > 0 ? ((int)$tsStat['conversions'] / $tsViews) * 100 : 0;
                                    ?>
                                    <div style="background: var(--color-cream); padding: var(--spacing-sm); border-radius: var(--radius-sm); margin-bottom: var(--spacing-xs); border-left: 3px solid var(--color-bark);">
                                        <div style="font-weight: 600; color: var(--color-forest); margin-bottom: 4px; font-size: var(--font-size-sm);"><?= htmlspecialchars($tsStat['traffic_source_name'] ?? 'Unknown') ?></div>
                                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px; font-size: 11px;">
                                            <div>Views: <?= number_format($tsViews) ?></div>
                                            <div>Clicks: <?= number_format($tsLpClicks) ?></div>
                                            <div>Conv: <?= number_format($tsStat['conversions']) ?></div>
                                            <div>Revenue: <span style="color: #28a745;">$<?= number_format($tsRevenue, 2) ?></span></div>
                                            <div>Cost: $<?= number_format($tsCost, 2) ?></div>
                                            <div>Profit: <span style="color: <?= $tsProfit >= 0 ? '#28a745' : '#d32f2f' ?>;">$<?= number_format($tsProfit, 2) ?></span></div>
                                            <div>ROI: <span style="color: <?= $tsRoi >= 0 ? '#28a745' : '#d32f2f' ?>;"><?= number_format($tsRoi, 1) ?>%</span></div>
                                            <div>EPC: $<?= number_format($tsEpc, 2) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
        <?php endif; ?>
