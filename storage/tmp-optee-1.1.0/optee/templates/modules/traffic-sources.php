<?php
/**
 * Traffic Sources module page.
 *
 * Scans for optee-traffic-source-* plugins and displays integration cards.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'get_plugins' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$all_plugins   = get_plugins();
$integrations  = [];

// Coming Soon placeholders (defined early so guide URLs are available for inactive plugins too).
// Slugs match plugin folder convention: optee-traffic-source-{slug}.
$coming_soon_all = [
    [
        'slug'       => 'propellerads',
        'name'       => 'PropellerAds',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/propellerads.svg',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%9F%A2-how-to-start-with-propellerads-traffic-source.16178/?r=571',
        'desc'       => 'Multisource advertising platform providing user acquisition and ad optimization for performance marketers, agencies, brands and affiliates.',
        'signup_url' => 'https://propellerads.com/',
    ],
    [
        'slug'       => 'rollerads',
        'name'       => 'RollerAds',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/rollerads.png',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%8E%AF-rollerads-landing-page-test.16041/?r=571',
        'desc'       => 'Advertising network with top-performing ad formats, in-house antifraud system and unique optimization algorithms.',
        'signup_url' => 'https://my.rollerads.com/ref/f91e220e7-516bca1895260',
    ],
    [
        'slug'       => 'adsterra',
        'name'       => 'Adsterra',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/adsterra.jpg',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%94%B4-how-to-start-with-adsterra-traffic-source-promo-code-included.14558/?r=571',
        'desc'       => 'Global advertising network with Partner Care approach. Multiple ad formats including popunder, social bar, push and native.',
        'signup_url' => 'https://adsterra.com/',
    ],
    [
        'slug'       => 'galaksion',
        'name'       => 'Galaksion',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/galaksion.svg',
        'guide_url'  => '',
        'desc'       => 'Advertising network to monetize your offers, turning visitors into profit. 1.5B+ daily impressions across 30,000+ partners.',
        'signup_url' => 'https://ssp-adv.galaksion.com/registration/advertiser?ri=da509adc-bf66-4363-bcd0-a60c4f92d576',
    ],
    [
        'slug'       => 'popads',
        'name'       => 'PopAds',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/popads.png',
        'guide_url'  => 'https://afflift.com/f/forums/beginners-course/?r=571',
        'desc'       => 'Popunder ad network with dynamic pricing, advanced targeting options and top quality traffic for advertisers.',
        'signup_url' => 'https://www.popads.net/users/refer/1249324',
    ],
    [
        'slug'       => 'popcash',
        'name'       => 'PopCash',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/popcash.png',
        'guide_url'  => '',
        'desc'       => 'Popunder ad network with 4B+ monthly visitors. Fast campaign setup, quick approvals and detailed hourly analytics.',
        'signup_url' => 'https://popcash.net/register/169763',
    ],
    [
        'slug'       => 'bidvertiser',
        'name'       => 'BidVertiser',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/bidvertiser.png',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%9F%A6-how-to-start-with-bidvertiser-traffic-source-promo-code-included.15384/?r=571',
        'desc'       => 'Self-serve advertising platform where optimization meets transparency. Multiple ad formats with advanced targeting and real-time optimization.',
        'signup_url' => 'http://my.bidvertiser.com/bdv/bidvertiser/bdv_ref.dbm?Ref_Option=adv&Ref_PID=939186',
    ],
    [
        'slug'       => 'mondiad',
        'name'       => 'Mondiad',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/mondiad.svg',
        'guide_url'  => 'https://afflift.com/f/threads/%E2%93%82%EF%B8%8F-how-to-start-with-mondiad-advertising-network-promo-code-included.15275/?r=571',
        'desc'       => 'Performance-driven advertising network with push, native, banner and interstitial ad formats. Advanced targeting and fast campaign approvals.',
        'signup_url' => 'https://mondiad.com?refid=5863',
    ],
    [
        'slug'       => 'adcash',
        'name'       => 'Adcash',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/adcash.png',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%9F%A1-how-to-start-with-adcash-traffic-source-promo-code-included.15035/?r=571',
        'desc'       => 'Global advertising platform with 300M+ daily unique users across 195 countries. Advanced targeting, anti-fraud technology and automated ROI optimization.',
        'signup_url' => 'https://adcash.com/',
    ],
    [
        'slug'       => 'hilltopads',
        'name'       => 'HilltopAds',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/hilltopads.png',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%9A%A9-how-to-start-with-hilltopads-ad-network-promo-code-included.14947/?r=571',
        'desc'       => 'Ad network and traffic monetization platform with premium inventory. Popunder, in-page push, video and banner ad formats.',
        'signup_url' => 'https://hilltopads.com/?ref=37923',
    ],
    [
        'slug'       => 'clickadu',
        'name'       => 'Clickadu',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/clickadu.svg',
        'guide_url'  => '',
        'desc'       => 'Global advertising network with 6B+ daily impressions. Popunder, push, in-page push, video, banner and SKIM ad formats with smart AI-based optimization.',
        'signup_url' => 'https://www.clickadu.com/',
    ],
    [
        'slug'       => 'pushhouse',
        'name'       => 'Push.House',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/pushhouse.svg',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%94%94-how-to-start-with-push-house-traffic-source-promo-code-included.14725/?r=571',
        'desc'       => 'Premium push notification advertising network. High-quality push traffic with advanced targeting and real-time statistics.',
        'signup_url' => 'https://push.house/?rid=94717',
        'dark_bg'    => true,
    ],
    [
        'slug'       => 'advertizer',
        'name'       => 'Advertizer',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/advertizer.png',
        'guide_url'  => '',
        'desc'       => 'Mobile ad network serving 5B+ ads monthly across push, pop, redirect and native formats. Quality traffic for affiliates with CPA and CPC models.',
        'signup_url' => 'https://advertizer.com/',
    ],
    [
        'slug'       => 'clickadilla',
        'name'       => 'ClickAdilla',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/traffic-sources/clickadilla.svg',
        'guide_url'  => '',
        'desc'       => 'Self-serve advertising network with 4.5B+ daily impressions across 13 ad formats including popunder, push, in-page, native, video and Telegram rewarded ads.',
        'signup_url' => 'https://clickadilla.com/?ref=07IhAbQX',
        'dark_bg'    => true,
    ],
];

// Build lookups from coming soon entries (used for inactive/active plugins too).
$known_guide_urls  = [];
$known_descs       = [];
$known_signup_urls = [];
$known_dark_bgs    = [];
foreach ( $coming_soon_all as $cs ) {
    if ( ! empty( $cs['guide_url'] ) ) {
        $known_guide_urls[ $cs['slug'] ] = $cs['guide_url'];
    }
    if ( ! empty( $cs['desc'] ) ) {
        $known_descs[ $cs['slug'] ] = $cs['desc'];
    }
    if ( ! empty( $cs['signup_url'] ) ) {
        $known_signup_urls[ $cs['slug'] ] = $cs['signup_url'];
    }
    if ( ! empty( $cs['dark_bg'] ) ) {
        $known_dark_bgs[ $cs['slug'] ] = true;
    }
}

foreach ( $all_plugins as $plugin_file => $plugin_data ) {
    $dir = dirname( $plugin_file );
    if ( $dir === '.' || ! str_starts_with( $dir, 'optee-traffic-source-' ) ) {
        continue;
    }

    $slug      = str_replace( 'optee-traffic-source-', '', $dir );
    $is_active = is_plugin_active( $plugin_file );

    // Strip "Optee - " prefix for display name.
    $display_name = preg_replace( '/^Optee\s*[-–—]\s*/i', '', $plugin_data['Name'] );

    // Check for logo (svg first, then png).
    $logo_url = '';
    foreach ( [ 'logo.svg', 'logo.png', 'logo.jpg' ] as $logo_file ) {
        $logo_path = WP_PLUGIN_DIR . '/' . $dir . '/assets/' . $logo_file;
        if ( file_exists( $logo_path ) ) {
            $logo_url = plugins_url( 'assets/' . $logo_file, WP_PLUGIN_DIR . '/' . $plugin_file );
            break;
        }
    }

    // Get guide URL from registered module (if active), or from known list.
    $guide_url = '';
    if ( $is_active ) {
        $module = $registry->get_module( $slug, 'traffic_source' );
        if ( $module ) {
            $guide_url = $module->get_guide_url();
        }
    }
    if ( empty( $guide_url ) && isset( $known_guide_urls[ $slug ] ) ) {
        $guide_url = $known_guide_urls[ $slug ];
    }

    $integrations[] = [
        'name'        => $display_name,
        'version'     => $plugin_data['Version'],
        'description' => $plugin_data['Description'],
        'slug'        => $slug,
        'plugin_file' => $plugin_file,
        'is_active'   => $is_active,
        'logo_url'    => $logo_url,
        'guide_url'   => $guide_url,
        'desc'        => $known_descs[ $slug ] ?? '',
        'signup_url'  => $known_signup_urls[ $slug ] ?? '',
        'dark_bg'     => $known_dark_bgs[ $slug ] ?? false,
    ];
}

// Sort installed integrations by name ASC.
usort( $integrations, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Traffic Sources</h2>
            <p class="mt-1 text-sm text-gray-500">Manage your traffic source integrations.</p>
        </div>
        <div class="relative" style="width:220px;">
            <input type="text" id="optee-ts-search" class="optee-input" placeholder="Search..." style="padding-left:2.25rem;padding-right:0.75rem;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute text-gray-400" style="left:0.65rem;top:50%;transform:translateY(-50%);display:block;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        </div>
    </div>

    <?php
    // Check for available updates.
    $update_data    = \Optee\Plugin\Updater::get_available_updates();
    $updates        = $update_data['updates'] ?? [];

    // Filter out Coming Soon entries that already have an installed plugin.
    $installed_slugs = array_column( $integrations, 'slug' );
    $api_available   = \Optee\Plugin\Updater::get_available_plugins();
    $api_slugs       = array_column( $api_available, 'slug' );

    // Split uninstalled into "installable" (on API) and "coming soon" (not on API).
    $installable  = [];
    $coming_soon  = [];
    foreach ( $coming_soon_all as $item ) {
        if ( in_array( $item['slug'], $installed_slugs, true ) ) {
            continue;
        }
        $full_slug = 'optee-traffic-source-' . $item['slug'];
        if ( in_array( $full_slug, $api_slugs, true ) ) {
            $item['full_slug'] = $full_slug;
            $installable[] = $item;
        } else {
            $coming_soon[] = $item;
        }
    }
    usort( $installable, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
    usort( $coming_soon, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
    ?>

    <div class="optee-cards-grid" style="gap:1.75rem;">
        <?php foreach ( $integrations as $integration ) :
            if ( $integration['is_active'] ) {
                $status_label = 'Activated';
                $status_class = 'optee-badge-green';
            } else {
                $status_label = 'Inactive';
                $status_class = 'optee-badge-gray';
            }
        ?>
            <div class="optee-card relative flex flex-col items-center text-center p-6 optee-ts-card" data-name="<?php echo esc_attr( strtolower( $integration['name'] ) ); ?>">
                <!-- Top row: Guide link (left) + Status badge (right) -->
                <div class="absolute flex items-center justify-between" style="top:0.75rem;left:0.75rem;right:0.75rem;">
                    <div>
                        <?php if ( ! empty( $integration['guide_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $integration['guide_url'] ); ?>"
                               target="_blank" rel="noopener noreferrer"
                               class="optee-badge-gray flex items-center gap-1 hover:text-gray-900" style="text-decoration:none;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                Guide
                            </a>
                        <?php endif; ?>
                    </div>
                    <span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                </div>

                <!-- Logo -->
                <div class="flex items-center justify-center w-full mb-4" style="height:80px;">
                    <?php if ( $integration['logo_url'] ) : ?>
                        <img src="<?php echo esc_url( $integration['logo_url'] ); ?>"
                             alt="<?php echo esc_attr( $integration['name'] ); ?>"
                             style="max-height:60px;max-width:180px;width:auto;<?php echo ! empty( $integration['dark_bg'] ) ? 'background:#1a1a2e;border-radius:6px;padding:8px 12px;' : ''; ?>">
                    <?php else : ?>
                        <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center text-gray-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Name & description -->
                <h3 class="text-lg font-semibold text-gray-900"><?php echo esc_html( $integration['name'] ); ?></h3>
                <?php
                // Surface a partner promo where the decision is actually made —
                // before signup and before funding — instead of only on the
                // Bonuses page, which people reach once they already have an
                // account. Renders nothing when the partner has no live offer.
                $bonus = \Optee\Plugin\Bonuses::for_slug( $integration['slug'] ?? '', 'traffic_source' );
                if ( $bonus ) {
                    $variant = 'badge';
                    include OPTEE_PLUGIN_DIR . 'templates/partials/bonus-hint.php';
                }
                ?>
                <?php if ( ! empty( $integration['desc'] ) ) : ?>
                    <p class="text-sm text-gray-500 mt-1" style="max-width:340px;">
                        <?php echo esc_html( $integration['desc'] ); ?>
                        <?php if ( ! empty( $integration['signup_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $integration['signup_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:text-brand-700" style="white-space:nowrap;">Sign Up</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <!-- Action -->
                <?php
                $full_slug   = 'optee-traffic-source-' . $integration['slug'];
                $has_update  = ! empty( $updates[ $full_slug ]['update_available'] );
                $new_version = $updates[ $full_slug ]['latest_version'] ?? '';
                ?>
                <div class="mt-auto pt-4 flex items-center gap-2">
                    <?php if ( $integration['is_active'] ) : ?>
                        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'traffic-sources', 'configure', [ 'slug' => $integration['slug'] ] ) ); ?>"
                           class="optee-btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                            Configure
                        </a>
                    <?php else : ?>
                        <button type="button"
                                class="optee-btn-success optee-activate-plugin"
                                data-plugin="<?php echo esc_attr( $integration['plugin_file'] ); ?>"
                                data-configure-url="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'traffic-sources', 'configure', [ 'slug' => $integration['slug'] ] ) ); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/></svg>
                            Activate
                        </button>
                    <?php endif; ?>
                    <?php if ( $has_update ) : ?>
                        <button type="button"
                                class="optee-update-from-card"
                                data-slug="<?php echo esc_attr( $full_slug ); ?>"
                                style="display:inline-flex;align-items:center;gap:4px;padding:0.5rem 1rem;background:#f59e0b;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:0.8125rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            Update
                        </button>
                    <?php endif; ?>
                </div>
                <p class="text-xs text-gray-400 mt-2">v<?php echo esc_html( $integration['version'] ); ?><?php if ( $has_update ) : ?> → <?php echo esc_html( $new_version ); ?><?php endif; ?></p>
            </div>
        <?php endforeach; ?>

        <!-- Installable from API -->
        <?php foreach ( $installable as $placeholder ) :
            $card_class   = 'optee-ts-card';
            $placeholder['slug'] = $placeholder['full_slug'];
            include OPTEE_PLUGIN_DIR . 'templates/partials/install-plugin-card.php';
        endforeach; ?>

        <!-- Coming Soon placeholders -->
        <?php foreach ( $coming_soon as $placeholder ) : ?>
            <div class="optee-card relative flex flex-col items-center text-center p-6 optee-ts-card" data-name="<?php echo esc_attr( strtolower( $placeholder['name'] ) ); ?>" style="opacity:0.6;">
                <!-- Top row: Guide link (left) + Badge (right) -->
                <div class="absolute flex items-center justify-between" style="top:0.75rem;left:0.75rem;right:0.75rem;opacity:1;">
                    <div>
                        <?php if ( ! empty( $placeholder['guide_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $placeholder['guide_url'] ); ?>"
                               target="_blank" rel="noopener noreferrer"
                               class="optee-badge-gray flex items-center gap-1 hover:text-gray-900" style="text-decoration:none;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                Guide
                            </a>
                        <?php endif; ?>
                    </div>
                    <span class="optee-badge-yellow">Coming Soon</span>
                </div>

                <!-- Logo -->
                <div class="flex items-center justify-center w-full mb-4" style="height:80px;">
                    <img src="<?php echo esc_url( $placeholder['logo_url'] ); ?>"
                         alt="<?php echo esc_attr( $placeholder['name'] ); ?>"
                         style="max-height:60px;max-width:180px;width:auto;<?php echo ! empty( $placeholder['dark_bg'] ) ? 'background:#1a1a2e;border-radius:6px;padding:8px 12px;' : ''; ?>">
                </div>

                <!-- Name & description -->
                <h3 class="text-lg font-semibold text-gray-900"><?php echo esc_html( $placeholder['name'] ); ?></h3>
                <?php
                // Surface a partner promo where the decision is actually made —
                // before signup and before funding — instead of only on the
                // Bonuses page, which people reach once they already have an
                // account. Renders nothing when the partner has no live offer.
                $bonus = \Optee\Plugin\Bonuses::for_slug( $placeholder['slug'] ?? '', 'traffic_source' );
                if ( $bonus ) {
                    $variant = 'badge';
                    include OPTEE_PLUGIN_DIR . 'templates/partials/bonus-hint.php';
                }
                ?>
                <?php if ( ! empty( $placeholder['desc'] ) ) : ?>
                    <p class="text-sm text-gray-500 mt-1" style="max-width:340px;">
                        <?php echo esc_html( $placeholder['desc'] ); ?>
                        <?php if ( ! empty( $placeholder['signup_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $placeholder['signup_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:text-brand-700" style="white-space:nowrap;">Sign Up</a>
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p class="text-xs text-gray-400 mt-1">Integration in development</p>
                <?php endif; ?>

                <!-- Vote button -->
                <div class="mt-auto pt-4">
                    <a href="#" class="optee-support-link optee-btn-primary flex items-center gap-1.5" data-redirect="/integrations" style="opacity:1;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></svg>
                        Vote
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
(function($) {
    // Search filter.
    $('#optee-ts-search').on('input', function() {
        var query = $(this).val().toLowerCase();
        $('.optee-ts-card').each(function() {
            var name = $(this).data('name');
            $(this).toggle(name.indexOf(query) !== -1);
        });
    });

    $(document).on('click', '.optee-activate-plugin', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var pluginFile = $btn.data('plugin');

        $btn.prop('disabled', true).text('Activating…');

        $.post(opteeData.ajaxUrl, {
            action: 'optee_activate_plugin',
            nonce:  opteeData.nonce,
            plugin_file: pluginFile
        })
        .done(function(res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
                window.location.reload();
            } else {
                Optee.Toast.show(res.data.message || 'Activation failed.', 'error');
                $btn.prop('disabled', false).text('Activate');
            }
        })
        .fail(function() {
            Optee.Toast.show('Network error. Please try again.', 'error');
            $btn.prop('disabled', false).text('Activate');
        });
    });
})(jQuery);
</script>
