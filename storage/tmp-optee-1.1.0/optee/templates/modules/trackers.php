<?php
/**
 * Trackers module page.
 *
 * Scans for optee-tracker-* plugins and displays integration cards.
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
// Slugs match plugin folder convention: optee-tracker-{slug}.
$coming_soon_all = [
    [
        'slug'       => 'simplekuma',
        'name'       => 'Simple KUMA',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/simplekuma.png',
        'guide_url'  => '',
        'desc'       => 'Self-hosted ad tracker for media buyers — campaigns, offers, landing-page rotation, and real-time click/conversion tracking on your own server.',
        'signup_url' => 'https://simplekuma.com/',
        'dark_bg'    => true,
    ],
    [
        'slug'       => 'bemob',
        'name'       => 'BeMob',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/bemob.svg',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%93%8A-how-to-start-with-bemob-tracker-promo-code-included.15281/?r=571',
        'desc'       => 'Ad tracking and optimization platform for media buyers and affiliate marketers. Campaign analysis, reporting and conversion optimization.',
        'signup_url' => 'https://bemob.com/?ref=6zeex1atj7&reg',
    ],
    [
        'slug'       => 'maxconv',
        'name'       => 'MaxConv',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/maxconv.svg',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%94%B5-how-to-start-with-maxconv-tracker.14810/?r=571',
        'desc'       => 'Cloud solution for tracking, analyzing and optimizing your marketing campaigns. Bot filtering, funnel analytics and multi-channel integration.',
        'signup_url' => 'https://app.maxconv.com/signup',
    ],
    [
        'slug'       => 'skro',
        'name'       => 'Skro',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/skro.svg',
        'guide_url'  => 'https://afflift.com/f/forums/beginners-course/?r=571',
        'desc'       => 'Ad tracker and conversion attribution platform for media buyers and affiliate marketers. Real-time reporting and analytics.',
        'signup_url' => 'https://app.skro.eu/login?refer=84C3CD0CEF',
    ],
    [
        'slug'       => 'funnelflux',
        'name'       => 'FunnelFlux Pro',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/funnelflux.svg',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%94%B4-how-to-start-with-funnelflux-tracker-50-discount-included.16068/?r=571',
        'desc'       => 'Journey tracking for performance marketers. Visual funnel builder with advanced analytics and real-time optimization.',
        'signup_url' => '',
    ],
    [
        'slug'       => 'clickflare',
        'name'       => 'ClickFlare',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/clickflare.svg',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%A4%9D-how-to-chat-with-clickflare-using-chatgpt.15839/?r=571',
        'desc'       => 'Ad tracker for marketers and media buyers. Comprehensive campaign tracking and optimization across multiple channels.',
        'signup_url' => '',
    ],
    [
        'slug'       => 'peerclick',
        'name'       => 'PeerClick',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/peerclick.svg',
        'guide_url'  => '',
        'desc'       => 'AI-powered ad tracking platform. Real-time attribution, autopilot optimization and intelligent traffic distribution.',
        'signup_url' => '',
    ],
    [
        'slug'       => 'voluum',
        'name'       => 'Voluum',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/voluum.svg',
        'guide_url'  => '',
        'desc'       => 'Cloud-based ad tracker integrating with 70+ ad platforms. Track, optimize and automate campaigns from a single dashboard.',
        'signup_url' => 'https://voluum.com/?rt=LsMbBQ%2FEc2pmlVUiYApvALibhqB%2BsD9AR%2BAY%2FIuv1e3EK2huJsVw0ON41X9p7Xeo',
    ],
    [
        'slug'       => 'cpvone',
        'name'       => 'CPV One',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/cpvone.png',
        'guide_url'  => '',
        'desc'       => 'Cloud performance marketing tracker for media buyers and agencies. Server-side tracking with first-party cookies and conversion APIs.',
        'signup_url' => '',
        'dark_bg'    => true,
    ],
    [
        'slug'       => 'adsbridge',
        'name'       => 'AdsBridge',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/adsbridge.svg',
        'guide_url'  => '',
        'desc'       => 'Affiliate tracking platform with built-in landing page builder. Manage and optimize all ad campaigns from a single platform.',
        'signup_url' => 'https://partner.adsbridge.com/registration/',
        'dark_bg'    => true,
    ],
    [
        'slug'       => 'redtrack',
        'name'       => 'RedTrack',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/redtrack.svg',
        'guide_url'  => '',
        'desc'       => 'Performance marketing analytics platform. Get conversion data your ad platforms can\'t show you, across Meta, Google, TikTok and more.',
        'signup_url' => '',
    ],
    [
        'slug'       => 'keitaro',
        'name'       => 'Keitaro',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/keitaro.svg',
        'guide_url'  => '',
        'desc'       => 'Ad performance tracker for affiliate marketers. Self-hosted solution with flexible traffic management and detailed analytics.',
        'signup_url' => '',
    ],
    [
        'slug'       => 'cpvlabpro',
        'name'       => 'CPV Lab Pro',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/cpvlabpro.svg',
        'guide_url'  => '',
        'desc'       => 'Self-hosted performance marketing tracker. Track all channels, split test pages, filter bots and attribute conversions accurately.',
        'signup_url' => '',
        'dark_bg'    => true,
    ],
    [
        'slug'       => 'binom',
        'name'       => 'Binom',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/binom.png',
        'guide_url'  => '',
        'desc'       => 'Universal self-hosted tracking platform for professionals (Binom v2). Fixed pricing, instant multi-level reporting and handles tens of millions of clicks daily.',
        'signup_url' => '',
    ],
    [
        'slug'       => 'metricanic',
        'name'       => 'Metricanic',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/trackers/metricanic.svg',
        'guide_url'  => '',
        'desc'       => 'Modern cloud ad tracker for media buyers and affiliate marketers. Real-time click and conversion tracking with pivot-style reporting, device and geo breakdowns, and postback automation.',
        'signup_url' => '',
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
    if ( $dir === '.' || ! str_starts_with( $dir, 'optee-tracker-' ) ) {
        continue;
    }

    $slug      = str_replace( 'optee-tracker-', '', $dir );
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
        $module = $registry->get_module( $slug, 'tracker' );
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
        'dark_bg'     => ! empty( $known_dark_bgs[ $slug ] ),
    ];
}

// Sort installed integrations by name ASC.
usort( $integrations, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Trackers</h2>
            <p class="mt-1 text-sm text-gray-500">Manage your tracker integrations.</p>
        </div>
        <div class="relative" style="width:220px;">
            <input type="text" id="optee-tracker-search" class="optee-input" placeholder="Search..." style="padding-left:2.25rem;padding-right:0.75rem;">
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
        $full_slug = 'optee-tracker-' . $item['slug'];
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
            <div class="optee-card relative flex flex-col items-center text-center p-6 optee-tracker-card" data-name="<?php echo esc_attr( strtolower( $integration['name'] ) ); ?>">
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
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>
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
                $bonus = \Optee\Plugin\Bonuses::for_slug( $integration['slug'] ?? '', 'tracker' );
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
                $full_slug   = 'optee-tracker-' . $integration['slug'];
                $has_update  = ! empty( $updates[ $full_slug ]['update_available'] );
                $new_version = $updates[ $full_slug ]['latest_version'] ?? '';
                ?>
                <div class="mt-auto pt-4 flex items-center gap-2">
                    <?php if ( $integration['is_active'] ) : ?>
                        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'trackers', 'configure', [ 'slug' => $integration['slug'] ] ) ); ?>"
                           class="optee-btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                            Configure
                        </a>
                    <?php else : ?>
                        <button type="button"
                                class="optee-btn-success optee-activate-plugin"
                                data-plugin="<?php echo esc_attr( $integration['plugin_file'] ); ?>"
                                data-configure-url="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'trackers', 'configure', [ 'slug' => $integration['slug'] ] ) ); ?>">
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
                <p class="text-xs text-gray-400 mt-2">v<?php echo esc_html( $integration['version'] ); ?><?php if ( $has_update ) : ?> &rarr; <?php echo esc_html( $new_version ); ?><?php endif; ?></p>
            </div>
        <?php endforeach; ?>

        <!-- Installable from API -->
        <?php foreach ( $installable as $placeholder ) :
            $card_class   = 'optee-tracker-card';
            $placeholder['slug'] = $placeholder['full_slug'];
            include OPTEE_PLUGIN_DIR . 'templates/partials/install-plugin-card.php';
        endforeach; ?>

        <!-- Coming Soon placeholders -->
        <?php foreach ( $coming_soon as $placeholder ) : ?>
            <div class="optee-card relative flex flex-col items-center text-center p-6 optee-tracker-card" data-name="<?php echo esc_attr( strtolower( $placeholder['name'] ) ); ?>" style="opacity:0.6;">
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
                $bonus = \Optee\Plugin\Bonuses::for_slug( $placeholder['slug'] ?? '', 'tracker' );
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
    $('#optee-tracker-search').on('input', function() {
        var query = $(this).val().toLowerCase();
        $('.optee-tracker-card').each(function() {
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
