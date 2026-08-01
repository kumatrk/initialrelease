<?php
// Get permission instance for navigation
$permission = $GLOBALS['permission'] ?? null;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Theme\ThemeRegistry;

$skVersionFile = __DIR__ . '/../../version.php';
$skVersionData = is_file($skVersionFile) ? include $skVersionFile : [];
$skAppVersion = is_array($skVersionData) ? (string) ($skVersionData['version'] ?? '1.1.5.2') : '1.1.5.2';

$userTheme = ThemeRegistry::normalize($GLOBALS['userTheme'] ?? ThemeRegistry::DEFAULT_THEME);
$themeOptions = ThemeRegistry::all();
$themeBase = ThemeRegistry::base($userTheme);
$logoFile = ThemeRegistry::logo($userTheme);
$themeClientConfig = ThemeRegistry::toClientConfig();
$themeCsrfToken = Csrf::ensureToken();
$sidebarCollapsed = !empty($GLOBALS['sidebarCollapsed']);
$dashboardChartsHidden = !empty($GLOBALS['dashboardChartsHidden']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($userTheme) ?>" data-theme-base="<?= htmlspecialchars($themeBase) ?>"<?= $sidebarCollapsed ? ' class="sidebar-collapsed"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <script>
    (function () {
        try {
            var serverTheme = <?= json_encode($userTheme, JSON_THROW_ON_ERROR) ?>;
            var serverBase = <?= json_encode($themeBase, JSON_THROW_ON_ERROR) ?>;
            document.documentElement.setAttribute('data-theme', serverTheme);
            document.documentElement.setAttribute('data-theme-base', serverBase);
            localStorage.setItem('kuma_theme', serverTheme);
            var serverSidebarCollapsed = <?= $sidebarCollapsed ? 'true' : 'false' ?>;
            document.documentElement.classList.toggle('sidebar-collapsed', serverSidebarCollapsed);
            localStorage.setItem('kuma_sidebar_collapsed', serverSidebarCollapsed ? '1' : '0');
        } catch (e) { /* ignore */ }
    })();
    </script>
    <title><?= htmlspecialchars($pageTitle ?? 'Simple KUMA') ?> - Simple KUMA</title>
    <link rel="icon" type="image/x-icon" href="<?= ASSETS_BASE_URL ?>/assets/images/favicon.ico">
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/themes.css">
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/theme-switcher.css">
    <!-- MOBILE DASHBOARD STYLES - To remove mobile styles, delete the line below and delete public/assets/css/mobile-dashboard.css -->
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-dashboard.css">
    <!-- MOBILE VISITORS STYLES - To remove mobile styles, delete the line below and delete public/assets/css/mobile-visitors.css -->
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-visitors.css">
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/conversion-log.css">
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-conversions.css">
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/campaign-list-filters.css">
    <!-- MOBILE CAMPAIGNS STYLES - To remove mobile styles, delete the line below and delete public/assets/css/mobile-campaigns.css -->
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-campaigns.css">
    <!-- MOBILE TRAFFIC SOURCES STYLES - To remove mobile styles, delete the line below and delete public/assets/css/mobile-traffic-sources.css -->
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-traffic-sources.css">
    <!-- MOBILE OFFERS STYLES - To remove mobile styles, delete the line below and delete public/assets/css/mobile-offers.css -->
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-offers.css">
    <!-- MOBILE LANDING PAGES STYLES - To remove mobile styles, delete the line below and delete public/assets/css/mobile-landing-pages.css -->
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-landing-pages.css">
    <!-- MOBILE NETWORKS STYLES - To remove mobile styles, delete the line below and delete public/assets/css/mobile-networks.css -->
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-networks.css">
    <!-- MOBILE POSTBACKS STYLES - To remove mobile styles, delete the line below and delete public/assets/css/mobile-postbacks.css -->
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-postbacks.css">
    <!-- MOBILE BILLING STYLES - To remove mobile styles, delete the line below and delete public/assets/css/mobile-billing.css -->
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-billing.css">
    <!-- MOBILE SETTINGS STYLES - To remove mobile styles, delete the line below and delete public/assets/css/mobile-settings.css -->
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-settings.css">
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/settings-layout.css?v=1">
</head>
<body>
    <div class="app-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <a href="<?= APP_BASE_URL ?>/index.php" class="sidebar-logo" title="Simple KUMA" data-label="Simple KUMA">
                    <img id="sidebar-logo-img"
                         class="sidebar-logo-img"
                         src="<?= ASSETS_BASE_URL ?>/assets/images/<?= htmlspecialchars($logoFile) ?>"
                         alt="Simple KUMA">
                </a>
                <button type="button"
                        class="sidebar-collapse-toggle"
                        id="sidebar-collapse-toggle"
                        aria-expanded="<?= $sidebarCollapsed ? 'false' : 'true' ?>"
                        aria-label="<?= $sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar' ?>"
                        title="<?= $sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar' ?>">
                    <span class="sidebar-collapse-icon" aria-hidden="true"></span>
                </button>
            </div>
            
            <nav class="sidebar-nav">
                <?php
                $hasNoRoles = empty($_SESSION['role_ids'] ?? []);
                $legacyNoRoles = $hasNoRoles && \SimpleKuma\Auth\Auth::allowsLegacyNoRolesFallback();
                ?>
                <!-- Main Section -->
                <div class="sidebar-nav-section">
                    <div class="sidebar-nav-title">Main</div>
                    <ul class="sidebar-nav-list">
                        <?php 
                        $shouldShow = !$permission || $permission->hasPermission(Permission::PERM_DASHBOARD_VIEW) || $legacyNoRoles;
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=dashboard" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/dashboard.png" alt="Dashboard" class="sidebar-nav-icon">
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php 
                        $shouldShow = !$permission || $permission->hasPermission(Permission::PERM_VISITOR_LOG_VIEW) || $legacyNoRoles;
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=visitors" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'visitors' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/visitorslog.png" alt="Visitor Log" class="sidebar-nav-icon">
                                <span>Visitor Log</span>
                            </a>
                        </li>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=conversions" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'conversions' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/conversionbear.png" alt="Conversion Log" class="sidebar-nav-icon">
                                <span>Conversion Log</span>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <!-- Tracking Section -->
                <?php 
                $shouldShowTracking = !$permission || $permission->hasAnyPermission([Permission::PERM_CAMPAIGN_VIEW, Permission::PERM_TRAFFIC_SOURCE_VIEW, Permission::PERM_OFFER_VIEW, Permission::PERM_LANDING_PAGE_VIEW, Permission::PERM_NETWORK_VIEW]) || $legacyNoRoles;
                if ($shouldShowTracking): 
                ?>
                <div class="sidebar-nav-section">
                    <div class="sidebar-nav-title">Tracking</div>
                    <ul class="sidebar-nav-list">
                        <?php 
                        $shouldShow = !$permission || $permission->hasPermission(Permission::PERM_CAMPAIGN_VIEW) || $legacyNoRoles;
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=campaign-list" 
                               class="sidebar-nav-link <?= in_array($currentPage ?? '', ['campaigns', 'campaign-list'], true) ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/campaigns.png" alt="Campaigns" class="sidebar-nav-icon">
                                <span>Campaigns</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php 
                        $shouldShow = !$permission || $permission->hasPermission(Permission::PERM_TRAFFIC_SOURCE_VIEW) || $legacyNoRoles;
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=traffic-sources" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'traffic-sources' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/trafficsources.png" alt="Traffic Sources" class="sidebar-nav-icon">
                                <span>Traffic Sources</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php 
                        $shouldShow = !$permission || $permission->hasPermission(Permission::PERM_OFFER_VIEW) || $legacyNoRoles;
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=offers" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'offers' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/offers.png" alt="Offers" class="sidebar-nav-icon">
                                <span>Offers</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php 
                        $shouldShow = !$permission || $permission->hasPermission(Permission::PERM_LANDING_PAGE_VIEW) || $legacyNoRoles;
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=landing-pages" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'landing-pages' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/landingpages.png" alt="Landing Pages" class="sidebar-nav-icon">
                                <span>Landing Pages</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php 
                        $shouldShow = !$permission || $permission->hasPermission(Permission::PERM_NETWORK_VIEW) || $legacyNoRoles;
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=networks" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'networks' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/networks.png" alt="Networks" class="sidebar-nav-icon">
                                <span>Networks</span>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Settings Section -->
                <?php 
                $shouldShowSystem = !$permission || $permission->hasAnyPermission([Permission::PERM_CAMPAIGN_VIEW, Permission::PERM_POSTBACK_VIEW, Permission::PERM_SETTINGS_VIEW]) || $legacyNoRoles;
                if ($shouldShowSystem): 
                ?>
                <div class="sidebar-nav-section">
                    <div class="sidebar-nav-title">System</div>
                    <ul class="sidebar-nav-list">
                        <?php 
                        $shouldShow = !$permission || $permission->hasPermission(Permission::PERM_CAMPAIGN_VIEW) || $legacyNoRoles;
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=click-lookup" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'click-lookup' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/clicklookbear.png" alt="Click Lookup" class="sidebar-nav-icon">
                                <span>Click Lookup</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php 
                        $shortLinksComingSoon = true; // Beta: hide for release - coming in future
                        $shouldShow = !$shortLinksComingSoon && (!$permission || $permission->hasPermission(Permission::PERM_CAMPAIGN_VIEW) || $legacyNoRoles);
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=short-links" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'short-links' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/cloakedbear.png" alt="Short Links" class="sidebar-nav-icon">
                                <span>Short Links</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php 
                        $shouldShow = !$permission || $permission->hasPermission(Permission::PERM_POSTBACK_VIEW) || $legacyNoRoles;
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=postback-urls" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'postback-urls' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/postbacks.png" alt="Postback URLs" class="sidebar-nav-icon">
                                <span>Postback URLs</span>
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php 
                        $shouldShow = !$permission || $permission->hasPermission(Permission::PERM_SETTINGS_VIEW) || $legacyNoRoles;
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=kuma-api" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'kuma-api' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/kumaapi.png" alt="Kuma API" class="sidebar-nav-icon">
                                <span>Kuma API</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php 
                        $shouldShow = !$permission || $permission->hasPermission(Permission::PERM_SETTINGS_VIEW) || $legacyNoRoles;
                        if ($shouldShow): 
                        ?>
                        <li class="sidebar-nav-item">
                            <a href="<?= APP_BASE_URL ?>/index.php?page=settings" 
                               class="sidebar-nav-link <?= ($currentPage ?? '') === 'settings' ? 'active' : '' ?>">
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/settings.png" alt="Settings" class="sidebar-nav-icon">
                                <span>Settings</span>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </nav>
            
            <div class="sidebar-footer">
                <div class="sidebar-footer-full">
                    Simple Kuma V <?= htmlspecialchars($skAppVersion) ?><br>
                    <span style="font-size: 10px;">Work is Never Over</span><br>
                    <a href="<?= APP_BASE_URL ?>/index.php?page=settings&tab=about" class="sidebar-footer-about">About Kuma</a>
                </div>
                <div class="sidebar-footer-collapsed" title="Simple Kuma V <?= htmlspecialchars($skAppVersion) ?>">
                    v<?= htmlspecialchars($skAppVersion) ?>
                </div>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Navbar -->
            <nav class="navbar">
                <div class="navbar-left">
                    <!-- MOBILE MENU TOGGLE - To remove, delete this button and the mobile-menu.js script -->
                    <button class="mobile-menu-toggle" id="mobile-menu-toggle" aria-label="Toggle menu">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                    <h1 class="navbar-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
                </div>
                <div class="navbar-right">
                    <div class="navbar-user">
                        <span><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></span>
                        <div class="navbar-avatar">
                            <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?>
                        </div>
                        <select id="theme-select" class="theme-select" aria-label="Theme">
                            <?php foreach ($themeOptions as $themeId => $themeLabel): ?>
                            <option value="<?= htmlspecialchars($themeId) ?>"<?= $userTheme === $themeId ? ' selected' : '' ?>><?= htmlspecialchars($themeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a href="<?= APP_BASE_URL ?>/logout.php" class="navbar-logout-btn">
                            Logout
                        </a>
                    </div>
                </div>
            </nav>
            
            <!-- Update Notification Banner (cache-only on render; GitHub refresh is async) -->
            <?php
            $currentPage = $GLOBALS['currentPage'] ?? '';
            $showUpdateCheck = !empty($currentPage) && $currentPage !== 'tracking';
            $updateBannerShown = false;
            $scheduleLazyUpdateCheck = false;
            if ($showUpdateCheck) {
                try {
                    require_once __DIR__ . '/../../src/Update/UpdateChecker.php';
                    $updateDb = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
                    if ($updateDb->connect_error) {
                        throw new \Exception('Database connection failed: ' . $updateDb->connect_error);
                    }
                    $updateChecker = new \SimpleKuma\Update\UpdateChecker($updateDb);

                    if ($updateChecker->isUpdateCheckEnabled()) {
                        // Never call GitHub during page render — use cache only.
                        // Stale cache still shows a known "update available" banner;
                        // a background fetch refreshes when the hour cache expires.
                        $updateInfo = $updateChecker->getCachedResult(true);
                        $scheduleLazyUpdateCheck = !$updateChecker->isCacheFresh();

                        if (
                            is_array($updateInfo)
                            && !empty($updateInfo['success'])
                            && !empty($updateInfo['update_available'])
                        ) {
                            $updateType = $updateInfo['update_type'] ?? 'patch';
                            $typeColors = [
                                'major' => '#d32f2f',
                                'minor' => '#f57c00',
                                'patch' => '#1976d2',
                                'hotfix' => '#c62828',
                            ];
                            $typeColor = $typeColors[$updateType] ?? '#1976d2';
                            $updateBannerShown = true;
                            ?>
                        <div id="update-notification-banner" style="background: linear-gradient(135deg, <?= $typeColor ?> 0%, <?= $typeColor ?>dd 100%); color: #ffffff; padding: 16px 24px; margin: 0; border-bottom: 2px solid rgba(255,255,255,0.2); box-shadow: 0 2px 8px rgba(0,0,0,0.1); position: relative; z-index: 100;">
                            <div style="display: flex; align-items: center; justify-content: space-between; max-width: 1400px; margin: 0 auto; flex-wrap: wrap; gap: 16px;">
                                <div style="display: flex; align-items: center; gap: 16px; flex: 1; min-width: 200px;">
                                    <span style="font-size: 28px;">🔔</span>
                                    <div>
                                        <strong style="font-size: 18px; display: block; margin-bottom: 4px;">Update Available</strong>
                                        <span style="font-size: 15px; opacity: 0.95;">Kuma <?= htmlspecialchars((string) $updateInfo['latest_version']) ?> is available (you're on <?= htmlspecialchars((string) $updateInfo['current_version']) ?>)</span>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <a href="?page=settings&tab=updates"
                                       style="padding: 10px 20px; background: rgba(255,255,255,0.2); color: #ffffff; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; text-decoration: none; font-size: 15px; font-weight: 600; transition: all 0.2s; white-space: nowrap;"
                                       onmouseover="this.style.background='rgba(255,255,255,0.3)'; this.style.borderColor='rgba(255,255,255,0.5)'"
                                       onmouseout="this.style.background='rgba(255,255,255,0.2)'; this.style.borderColor='rgba(255,255,255,0.3)'">
                                        View Details
                                    </a>
                                    <button type="button" onclick="document.getElementById('update-notification-banner').style.display='none'; localStorage.setItem('update_notification_dismissed_<?= htmlspecialchars((string) $updateInfo['latest_version']) ?>', 'true');"
                                            style="padding: 10px 14px; background: transparent; color: #ffffff; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer; font-size: 22px; line-height: 1; transition: all 0.2s;"
                                            onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                                            onmouseout="this.style.background='transparent'"
                                            title="Dismiss">
                                        ×
                                    </button>
                                </div>
                            </div>
                        </div>
                        <script>
                        if (localStorage.getItem('update_notification_dismissed_<?= htmlspecialchars((string) $updateInfo['latest_version']) ?>') === 'true') {
                            document.getElementById('update-notification-banner').style.display = 'none';
                        }
                        </script>
                            <?php
                        }
                    }
                    $updateDb->close();
                } catch (\Exception $e) {
                    error_log('Update check error: ' . $e->getMessage());
                }
            }

            if ($scheduleLazyUpdateCheck && !$updateBannerShown):
                $lazyUpdateCheckUrl = APP_BASE_URL . '/api-check-updates.php';
                ?>
            <div id="update-notification-slot"></div>
            <script>
            (function () {
                var apiUrl = <?= json_encode($lazyUpdateCheckUrl, JSON_THROW_ON_ERROR) ?>;
                var typeColors = { major: '#d32f2f', minor: '#f57c00', patch: '#1976d2', hotfix: '#c62828' };

                function showBanner(info) {
                    if (!info || !info.update_available || !info.latest_version) return;
                    if (localStorage.getItem('update_notification_dismissed_' + info.latest_version) === 'true') return;
                    if (document.getElementById('update-notification-banner')) return;

                    var color = typeColors[info.update_type] || '#1976d2';
                    var slot = document.getElementById('update-notification-slot');
                    if (!slot) return;

                    var latest = String(info.latest_version);
                    var current = String(info.current_version || <?= json_encode($skAppVersion, JSON_THROW_ON_ERROR) ?>);
                    var esc = function (s) {
                        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
                    };

                    slot.outerHTML =
                        '<div id="update-notification-banner" style="background: linear-gradient(135deg, ' + color + ' 0%, ' + color + 'dd 100%); color: #ffffff; padding: 16px 24px; margin: 0; border-bottom: 2px solid rgba(255,255,255,0.2); box-shadow: 0 2px 8px rgba(0,0,0,0.1); position: relative; z-index: 100;">' +
                        '<div style="display: flex; align-items: center; justify-content: space-between; max-width: 1400px; margin: 0 auto; flex-wrap: wrap; gap: 16px;">' +
                        '<div style="display: flex; align-items: center; gap: 16px; flex: 1; min-width: 200px;">' +
                        '<span style="font-size: 28px;">🔔</span><div>' +
                        '<strong style="font-size: 18px; display: block; margin-bottom: 4px;">Update Available</strong>' +
                        '<span style="font-size: 15px; opacity: 0.95;">Kuma ' + esc(latest) + ' is available (you\'re on ' + esc(current) + ')</span>' +
                        '</div></div>' +
                        '<div style="display: flex; align-items: center; gap: 12px;">' +
                        '<a href="?page=settings&tab=updates" style="padding: 10px 20px; background: rgba(255,255,255,0.2); color: #ffffff; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; text-decoration: none; font-size: 15px; font-weight: 600; white-space: nowrap;">View Details</a>' +
                        '<button type="button" id="update-notification-dismiss" style="padding: 10px 14px; background: transparent; color: #ffffff; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer; font-size: 22px; line-height: 1;" title="Dismiss">×</button>' +
                        '</div></div></div>';

                    var dismissBtn = document.getElementById('update-notification-dismiss');
                    if (dismissBtn) {
                        dismissBtn.addEventListener('click', function () {
                            var banner = document.getElementById('update-notification-banner');
                            if (banner) banner.style.display = 'none';
                            localStorage.setItem('update_notification_dismissed_' + latest, 'true');
                        });
                    }
                }

                function runCheck() {
                    fetch(apiUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(showBanner)
                        .catch(function () { /* ignore */ });
                }

                if ('requestIdleCallback' in window) {
                    requestIdleCallback(runCheck, { timeout: 4000 });
                } else {
                    setTimeout(runCheck, 1500);
                }
            })();
            </script>
            <?php elseif ($scheduleLazyUpdateCheck): ?>
            <script>
            (function () {
                var apiUrl = <?= json_encode(APP_BASE_URL . '/api-check-updates.php', JSON_THROW_ON_ERROR) ?>;
                function runCheck() {
                    fetch(apiUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                        .catch(function () { /* ignore */ });
                }
                if ('requestIdleCallback' in window) {
                    requestIdleCallback(runCheck, { timeout: 4000 });
                } else {
                    setTimeout(runCheck, 1500);
                }
            })();
            </script>
            <?php endif; ?>
            
            <!-- Content Container -->
            <div class="content-container">
                <?php if (isset($content)) {
                    echo $content;
                } else {
                    // If no content variable, include the content section
                    $this->renderContent();
                } ?>
            </div>
        </main>
    </div>
    
    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>
    
    <!-- MOBILE MENU JAVASCRIPT - To remove mobile menu, delete this script and the mobile-menu-toggle button -->
    <script>
    (function() {
        const menuToggle = document.getElementById('mobile-menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        
        if (!menuToggle || !sidebar || !overlay) return;
        
        function toggleMenu() {
            const isOpen = sidebar.classList.contains('open');
            sidebar.classList.toggle('open');
            menuToggle.classList.toggle('active');
            overlay.classList.toggle('active');
            
            // Prevent body scroll when menu is open
            if (!isOpen) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }
        
        function closeMenu() {
            sidebar.classList.remove('open');
            menuToggle.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleMenu();
        });
        
        overlay.addEventListener('click', closeMenu);
        
        // Close menu when clicking a sidebar link
        const sidebarLinks = sidebar.querySelectorAll('.sidebar-nav-link');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', closeMenu);
        });
        
        // Close menu on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                closeMenu();
            }
        });
    })();
    </script>
    <script>
    window.KUMA_THEME_CONFIG = {
        apiUrl: <?= json_encode(APP_BASE_URL . '/api-user-theme.php', JSON_THROW_ON_ERROR) ?>,
        csrfToken: <?= json_encode($themeCsrfToken, JSON_THROW_ON_ERROR) ?>,
        serverTheme: <?= json_encode($userTheme, JSON_THROW_ON_ERROR) ?>,
        assetsBaseUrl: <?= json_encode(ASSETS_BASE_URL . '/assets/images/', JSON_THROW_ON_ERROR) ?>,
        themes: <?= json_encode($themeClientConfig, JSON_THROW_ON_ERROR) ?>
    };
    window.KUMA_UI_PREFS_CONFIG = {
        apiUrl: <?= json_encode(APP_BASE_URL . '/api-user-ui-prefs.php', JSON_THROW_ON_ERROR) ?>,
        csrfToken: <?= json_encode($themeCsrfToken, JSON_THROW_ON_ERROR) ?>,
        sidebarCollapsed: <?= $sidebarCollapsed ? 'true' : 'false' ?>,
        dashboardChartsHidden: <?= $dashboardChartsHidden ? 'true' : 'false' ?>
    };
    </script>
    <script src="<?= ASSETS_BASE_URL ?>/assets/js/theme-switcher.js"></script>
    <script src="<?= ASSETS_BASE_URL ?>/assets/js/ui-prefs.js"></script>
</body>
</html>

