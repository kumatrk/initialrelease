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
$logoFile = $userTheme === 'dark' ? 'darkmodelogo2.png' : 'mainlogo.png';
$themeCsrfToken = Csrf::ensureToken();
$sidebarCollapsed = !empty($GLOBALS['sidebarCollapsed']);
$dashboardChartsHidden = !empty($GLOBALS['dashboardChartsHidden']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($userTheme) ?>"<?= $sidebarCollapsed ? ' class="sidebar-collapsed"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <script>
    (function () {
        try {
            var serverTheme = <?= json_encode($userTheme, JSON_THROW_ON_ERROR) ?>;
            document.documentElement.setAttribute('data-theme', serverTheme);
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
                         data-logo-light="<?= ASSETS_BASE_URL ?>/assets/images/mainlogo.png"
                         data-logo-dark="<?= ASSETS_BASE_URL ?>/assets/images/darkmodelogo2.png"
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
            
            <!-- Update Notification Banner -->
            <?php
            // Check for updates if enabled (only on admin pages, not tracking endpoints)
            $currentPage = $GLOBALS['currentPage'] ?? '';
            $showUpdateCheck = !empty($currentPage) && $currentPage !== 'tracking';
            if ($showUpdateCheck) {
                try {
                    require_once __DIR__ . '/../../src/Update/UpdateChecker.php';
                    // Always create a fresh database connection for update check
                    // This avoids issues with closed connections from the main request
                    $updateDb = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
                    if ($updateDb->connect_error) {
                        throw new \Exception("Database connection failed: " . $updateDb->connect_error);
                    }
                    $updateChecker = new \SimpleKuma\Update\UpdateChecker($updateDb);
                    
                    // TEST MODE: Force show notification banner (remove this after testing)
                    $testMode = false; // Set to false to disable test mode
                    
                    if ($testMode) {
                        // Show test notification banner
                        $updateInfo = [
                            'success' => true,
                            'update_available' => true,
                            'current_version' => $skAppVersion,
                            'latest_version' => '1.2.3',
                            'update_type' => 'minor'
                        ];
                    } elseif ($updateChecker->isUpdateCheckEnabled()) {
                        // Use cached result from database instead of making API call on every page load
                        // This prevents blocking page loads with slow API calls
                        $lastCheck = $updateChecker->getLastUpdateCheck();
                        if ($lastCheck) {
                            // Use cached result from database
                            $updateInfo = [
                                'success' => true,
                                'current_version' => $lastCheck['current_version'] ?? $skAppVersion,
                                'latest_version' => $lastCheck['latest_version'] ?? null,
                                'update_available' => (bool)($lastCheck['update_available'] ?? false),
                                'update_type' => $lastCheck['update_type'] ?? null,
                                'changelog' => $lastCheck['changelog'] ?? '',
                                'release_url' => $lastCheck['release_url'] ?? '',
                                'checked_at' => strtotime($lastCheck['checked_at'] ?? 'now')
                            ];
                        } else {
                            // No cached result, check in background (non-blocking)
                            // For now, just return no update to avoid blocking
                            $updateInfo = ['success' => false, 'update_available' => false];
                        }
                    } else {
                        $updateInfo = ['success' => false, 'update_available' => false];
                    }
                    
                    if ($updateInfo['success'] && $updateInfo['update_available']) {
                        $updateType = $updateInfo['update_type'] ?? 'patch';
                        $typeColors = [
                            'major' => '#d32f2f',
                            'minor' => '#f57c00',
                            'patch' => '#1976d2',
                            'hotfix' => '#c62828'
                        ];
                        $typeColor = $typeColors[$updateType] ?? '#1976d2';
                        ?>
                        <div id="update-notification-banner" style="background: linear-gradient(135deg, <?= $typeColor ?> 0%, <?= $typeColor ?>dd 100%); color: #ffffff; padding: 16px 24px; margin: 0; border-bottom: 2px solid rgba(255,255,255,0.2); box-shadow: 0 2px 8px rgba(0,0,0,0.1); position: relative; z-index: 100;">
                            <div style="display: flex; align-items: center; justify-content: space-between; max-width: 1400px; margin: 0 auto; flex-wrap: wrap; gap: 16px;">
                                <div style="display: flex; align-items: center; gap: 16px; flex: 1; min-width: 200px;">
                                    <span style="font-size: 28px;">🔔</span>
                                    <div>
                                        <strong style="font-size: 18px; display: block; margin-bottom: 4px;">Update Available</strong>
                                        <span style="font-size: 15px; opacity: 0.95;">Kuma <?= htmlspecialchars($updateInfo['latest_version']) ?> is available (you're on <?= htmlspecialchars($updateInfo['current_version']) ?>)</span>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <a href="?page=settings&tab=updates" 
                                       style="padding: 10px 20px; background: rgba(255,255,255,0.2); color: #ffffff; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; text-decoration: none; font-size: 15px; font-weight: 600; transition: all 0.2s; white-space: nowrap;"
                                       onmouseover="this.style.background='rgba(255,255,255,0.3)'; this.style.borderColor='rgba(255,255,255,0.5)'"
                                       onmouseout="this.style.background='rgba(255,255,255,0.2)'; this.style.borderColor='rgba(255,255,255,0.3)'">
                                        View Details
                                    </a>
                                    <button onclick="document.getElementById('update-notification-banner').style.display='none'; localStorage.setItem('update_notification_dismissed_<?= htmlspecialchars($updateInfo['latest_version']) ?>', 'true');" 
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
                        // In test mode, always show the banner and clear any previous dismissals
                        <?php if ($testMode): ?>
                        // Clear all update notification dismissals for testing
                        Object.keys(localStorage).forEach(key => {
                            if (key.startsWith('update_notification_dismissed_')) {
                                localStorage.removeItem(key);
                            }
                        });
                        // Ensure banner is visible
                        document.getElementById('update-notification-banner').style.display = '';
                        <?php else: ?>
                        // Check if user previously dismissed this version (only in non-test mode)
                        if (localStorage.getItem('update_notification_dismissed_<?= htmlspecialchars($updateInfo['latest_version']) ?>') === 'true') {
                            document.getElementById('update-notification-banner').style.display = 'none';
                        }
                        <?php endif; ?>
                        </script>
                        <?php
                    }
                } catch (\Exception $e) {
                    // Silently fail - don't break the page if update check fails
                    error_log("Update check error: " . $e->getMessage());
                }
            }
            ?>
            
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
        serverTheme: <?= json_encode($userTheme, JSON_THROW_ON_ERROR) ?>
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

