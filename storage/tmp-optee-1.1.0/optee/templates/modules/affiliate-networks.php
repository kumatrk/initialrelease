<?php
/**
 * Affiliate Networks module page.
 *
 * Scans for optee-affiliate-network-* plugins and displays integration cards.
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
// Slugs match plugin folder convention: optee-affiliate-network-{slug}.
$coming_soon_all = [
    [
        'slug'       => 'pinup',
        'name'       => 'PIN-UP Partners',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/pinup.svg',
        'guide_url'  => '',
        'desc'       => 'Direct iGaming affiliate program for the PIN-UP brand. CPA, RevShare and hybrid deals across casino and sportsbook, with per-player and aggregated statistics.',
        'signup_url' => 'https://pu-partners.com/?referral=sq4r40zph6',
    ],
    [
        'slug'       => 'ogads',
        'name'       => 'OGAds',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/ogads.png',
        'guide_url'  => '',
        'desc'       => 'Incentive mobile and desktop content locking network. Monetize traffic with app installs, surveys and content offers.',
        'signup_url' => 'https://members.ogads.com/register?r=149790',
    ],
    [
        'slug'       => 'monetizer',
        'name'       => 'Monetizer',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/monetizer.png',
        'guide_url'  => '',
        'desc'       => 'Revolutionary monetization platform which helps traffic sources, publishers & networks monetize their global inventory.',
        'signup_url' => 'https://www.monetizer.com/?pid=3529',
    ],
    [
        'slug'       => 'zeydoo',
        'name'       => 'Zeydoo',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/zeydoo.svg',
        'guide_url'  => 'https://afflift.com/f/forums/beginners-course/?r=571',
        'desc'       => 'Global CPA platform built for performance. Exclusive direct offers across surveys, giveaways, finance, utilities and more.',
        'signup_url' => 'https://app.zeydoo.com/signUp?ref_id=ejTx',
    ],
    [
        'slug'       => 'mylead',
        'name'       => 'MyLead',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/mylead.svg',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%92%B0-how-to-start-with-mylead-affiliate-network.15697/?r=571',
        'desc'       => 'Affiliate platform for publishers with 7,000+ programs across 30+ categories. Content lockers, multiple earning models and dedicated support.',
        'signup_url' => 'https://mylead.global/r/bkR6or',
        'dark_bg'    => true,
    ],
    [
        'slug'       => 'pdl-profit',
        'name'       => 'PDL-Profit',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/pdl-profit.svg',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%92%B0-pdl-profit-review-daily-payments-and-4000-free-sms-per-month.13723/?r=571',
        'desc'       => 'CPA network specializing in payday loan offers. Daily payments, in-house offers and dedicated affiliate support.',
        'signup_url' => 'https://pdl-profit.com?rid=PU1ETnhjRE4=',
        'dark_bg'    => true,
    ],
    [
        'slug'       => 'cparoll',
        'name'       => 'CpaRoll',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/cparoll.png',
        'guide_url'  => '',
        'desc'       => 'Performance affiliate network with high-converting offers from direct advertisers. App installs, email opt-ins, e-commerce and more.',
        'signup_url' => 'https://platform.cparoll.com/affiliate/signup/?p=2FP26QHW1',
    ],
    [
        'slug'       => 'mobipium',
        'name'       => 'MOBIPIUM',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/mobipium.png',
        'guide_url'  => 'https://afflift.com/f/forums/pops-guide/?r=571',
        'desc'       => 'Global mobile performance network. Exclusive offers with competitive payouts from 100+ direct advertisers.',
        'signup_url' => 'https://affiliates.mobipium.com/signup?referral=ref',
    ],
    [
        'slug'       => 'cpalead',
        'name'       => 'CPAlead',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/cpalead.png',
        'guide_url'  => '',
        'desc'       => 'Top CPA & content-locker network with 2,500+ direct offers. Mobile installs, sweeps, surveys, and rewards across global geos.',
        'signup_url' => 'https://www.cpalead.com/register?ref=754512',
    ],
    [
        'slug'       => 'cpagrip',
        'name'       => 'CPAGrip',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/cpagrip.png',
        'guide_url'  => '',
        'desc'       => 'Incentive CPA network with content lockers, surveys, PIN submits and mobile installs. JSON offer feed for easy integration.',
        'signup_url' => 'https://www.cpagrip.com/signup.php?ref=163480',
        'dark_bg'    => true,
    ],
    [
        'slug'       => 'imonetizeit',
        'name'       => 'iMonetizeIt',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/imonetizeit.svg',
        'guide_url'  => '',
        'desc'       => 'CPA affiliate network and smartlink platform. Monetize traffic globally across dating, gaming, sweepstakes and more.',
        'signup_url' => 'https://partner.imonetizeit.com/registration',
    ],
    [
        'slug'       => 'traffic-company',
        'name'       => 'Traffic Company',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/traffic-company.svg',
        'guide_url'  => '',
        'desc'       => 'CPA network with in-house Click to Call, mobile content and dating offers. Smart linking technology for maximum conversions.',
        'signup_url' => 'https://www.trafficcompany.com/signup?p=22218',
    ],
    [
        'slug'       => 'cpicash',
        'name'       => 'CPI Cash',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/affiliate-networks/cpicash.png',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%91%91-how-to-start-with-cpi-cash-affiliate-network.15781/?r=571',
        'desc'       => 'Mobile CPI and CPA performance network. Direct app install offers across global geos, runs on the Swaarm platform.',
        'signup_url' => 'https://partners.cpicash.com/signup?pub_ref=127',
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
    if ( $dir === '.' || ! str_starts_with( $dir, 'optee-affiliate-network-' ) ) {
        continue;
    }

    $slug      = str_replace( 'optee-affiliate-network-', '', $dir );
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
        $module = $registry->get_module( $slug, 'affiliate_network' );
        if ( $module ) {
            $guide_url = $module->get_guide_url();
        }
    }
    if ( empty( $guide_url ) && isset( $known_guide_urls[ $slug ] ) ) {
        $guide_url = $known_guide_urls[ $slug ];
    }

    // Dark-bg flag: prefer the live module's get_logo_dark_bg() if available, otherwise fall back to the coming-soon hint.
    $dark_bg = ! empty( $known_dark_bgs[ $slug ] );
    if ( $is_active && isset( $module ) && $module && method_exists( $module, 'get_logo_dark_bg' ) ) {
        $dark_bg = (bool) $module->get_logo_dark_bg();
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
        'dark_bg'     => $dark_bg,
    ];
}

// Sort installed integrations by name ASC.
usort( $integrations, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Affiliate Networks</h2>
            <p class="mt-1 text-sm text-gray-500">Manage your affiliate network integrations.</p>
        </div>
        <div class="relative" style="width:220px;">
            <input type="text" id="optee-an-search" class="optee-input" placeholder="Search..." style="padding-left:2.25rem;padding-right:0.75rem;">
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
        $full_slug = 'optee-affiliate-network-' . $item['slug'];
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

    <?php if ( empty( $integrations ) && empty( $coming_soon ) ) : ?>
        <div class="optee-card text-center py-12" style="max-width:400px;">
            <div class="mx-auto mb-4 w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-2">No Affiliate Networks Installed</h3>
            <p class="text-sm text-gray-500 mx-auto">Install an affiliate network module plugin to track offers and conversions.</p>
        </div>
    <?php else : ?>
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
            <div class="optee-card relative flex flex-col items-center text-center p-6 optee-an-card" data-name="<?php echo esc_attr( strtolower( $integration['name'] ) ); ?>">
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
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>
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
                $bonus = \Optee\Plugin\Bonuses::for_slug( $integration['slug'] ?? '', 'affiliate_network' );
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
                $full_slug   = 'optee-affiliate-network-' . $integration['slug'];
                $has_update  = ! empty( $updates[ $full_slug ]['update_available'] );
                $new_version = $updates[ $full_slug ]['latest_version'] ?? '';
                ?>
                <div class="mt-auto pt-4 flex items-center gap-2">
                    <?php if ( $integration['is_active'] ) : ?>
                        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'affiliate-networks', 'configure', [ 'slug' => $integration['slug'] ] ) ); ?>"
                           class="optee-btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                            Configure
                        </a>
                    <?php else : ?>
                        <button type="button"
                                class="optee-btn-success optee-activate-plugin"
                                data-plugin="<?php echo esc_attr( $integration['plugin_file'] ); ?>"
                                data-configure-url="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'affiliate-networks', 'configure', [ 'slug' => $integration['slug'] ] ) ); ?>">
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
            $card_class   = 'optee-an-card';
            $placeholder['slug'] = $placeholder['full_slug'];
            include OPTEE_PLUGIN_DIR . 'templates/partials/install-plugin-card.php';
        endforeach; ?>

        <!-- Coming Soon placeholders -->
        <?php foreach ( $coming_soon as $placeholder ) : ?>
            <div class="optee-card relative flex flex-col items-center text-center p-6 optee-an-card" data-name="<?php echo esc_attr( strtolower( $placeholder['name'] ) ); ?>" style="opacity:0.6;">
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
                <div class="flex items-center justify-center w-full mb-4" style="height:80px;">
                    <?php if ( ! empty( $placeholder['logo_url'] ) ) : ?>
                        <img src="<?php echo esc_url( $placeholder['logo_url'] ); ?>"
                             alt="<?php echo esc_attr( $placeholder['name'] ); ?>"
                             style="max-height:60px;max-width:180px;width:auto;<?php echo ! empty( $placeholder['dark_bg'] ) ? 'background:#1a1a2e;border-radius:6px;padding:8px 12px;' : ''; ?>">
                    <?php else : ?>
                        <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center text-gray-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>
                        </div>
                    <?php endif; ?>
                </div>
                <h3 class="text-lg font-semibold text-gray-900"><?php echo esc_html( $placeholder['name'] ); ?></h3>
                <?php
                // Surface a partner promo where the decision is actually made —
                // before signup and before funding — instead of only on the
                // Bonuses page, which people reach once they already have an
                // account. Renders nothing when the partner has no live offer.
                $bonus = \Optee\Plugin\Bonuses::for_slug( $placeholder['slug'] ?? '', 'affiliate_network' );
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
    <?php endif; ?>
</div>

<script>
(function($) {
    $('#optee-an-search').on('input', function() {
        var query = $(this).val().toLowerCase();
        $('.optee-an-card').each(function() {
            $(this).toggle($(this).data('name').indexOf(query) !== -1);
        });
    });

    $(document).on('click', '.optee-activate-plugin', function(e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true).text('Activating…');
        $.post(opteeData.ajaxUrl, {
            action: 'optee_activate_plugin',
            nonce:  opteeData.nonce,
            plugin_file: $btn.data('plugin')
        }).done(function(res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
                window.location.reload();
            } else {
                Optee.Toast.show(res.data.message || 'Activation failed.', 'error');
                $btn.prop('disabled', false).text('Activate');
            }
        }).fail(function() {
            Optee.Toast.show('Network error. Please try again.', 'error');
            $btn.prop('disabled', false).text('Activate');
        });
    });
})(jQuery);
</script>
