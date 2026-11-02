<?php
/**
 * Monetization Platforms module page.
 *
 * Scans for optee-monetization-* plugins and displays integration cards.
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
// Slugs match plugin folder convention: optee-monetization-{slug}.
$coming_soon_all = [
    [
        'slug'       => 'monetag',
        'name'       => 'Monetag',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/monetization-platforms/monetag.svg',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%92%A1-monetag-vs-monetizer-smartlink-test.16206/?r=571',
        'desc'       => 'Platform for monetization of desktop, mobile web and Telegram Mini App audiences, remnant ad inventory, and social traffic.',
        'signup_url' => 'https://monetag.com/?ref_id=Tq4g',
    ],
    [
        'slug'       => 'rollerads-publisher',
        'name'       => 'RollerAds Publisher',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/monetization-platforms/rollerads.png',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%8E%AF-rollerads-landing-page-test.16041/?r=571',
        'desc'       => 'Push notification ad network for publishers. Monetize traffic with native push subscriptions.',
        'signup_url' => 'https://my.rollerads.com/ref/f91e220e7-516bca1895260',
    ],
    [
        'slug'       => 'propush',
        'name'       => 'ProPush',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/monetization-platforms/propush.png',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%94%94-is-propush-still-worth-it-ive-tested-3-push-monetization-platforms.12275/?r=571',
        'desc'       => 'Platform for monetization of traffic with push subscriptions and other monetization tools.',
        'signup_url' => 'https://propush.me/?ref_id=ZCZf',
    ],
    [
        'slug'       => 'partners-house',
        'name'       => 'Partners.House',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/monetization-platforms/partners-house.svg',
        'guide_url'  => 'https://afflift.com/f/threads/%F0%9F%94%A5-partners-house-follow-along-let%E2%80%99s-test-push-revenue-for-10-different-geo-s.13589/?r=571',
        'desc'       => 'Push subscription monetization platform. Earn from push notification ads across 185+ GEOs.',
        'signup_url' => 'https://partners.house/?rid=182835',
    ],
    [
        'slug'       => 'monetizer',
        'name'       => 'Monetizer',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/monetization-platforms/monetizer.png',
        'guide_url'  => '',
        'desc'       => 'Revolutionary monetization platform which helps traffic sources, publishers & networks monetize their global inventory.',
        'signup_url' => 'https://www.monetizer.com/?pid=3529',
    ],
    [
        'slug'       => 'zeydoo',
        'name'       => 'Zeydoo (Monetization)',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/monetization-platforms/zeydoo.svg',
        'guide_url'  => '',
        'desc'       => 'Monetization network with push subscriptions and smartlinks. Earn from your traffic with advanced targeting and high eCPMs.',
        'signup_url' => 'https://zeydoo.com/?ref_id=ejTx',
    ],
    [
        'slug'       => 'hilltopads-publisher',
        'name'       => 'HilltopAds Publisher',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/monetization-platforms/hilltopads.svg',
        'guide_url'  => 'https://hilltopads.com/publishers-help/en/collections/11517408-social-media-monetization',
        'desc'       => 'Ad network for publishers with popunder, in-page push, video, and banner ad formats. High CPMs across desktop and mobile traffic.',
        'signup_url' => 'https://hilltopads.com/?ref=37923',
    ],
    [
        'slug'       => 'tacoloco-publisher',
        'name'       => 'TacoLoco (Publisher)',
        'logo_url'   => OPTEE_PLUGIN_URL . 'assets/images/monetization-platforms/tacoloco.png',
        'guide_url'  => '',
        'desc'       => 'Push and in-page push monetization. Collect subscribers and earn from push ad campaigns.',
        'signup_url' => 'https://tacolo.co/',
    ],
];

// Build lookups from coming soon entries (used for inactive/active plugins too).
$known_guide_urls  = [];
$known_descs       = [];
$known_signup_urls = [];
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
}

foreach ( $all_plugins as $plugin_file => $plugin_data ) {
    $dir = dirname( $plugin_file );
    if ( $dir === '.' || ! str_starts_with( $dir, 'optee-monetization-' ) ) {
        continue;
    }

    $slug      = str_replace( 'optee-monetization-', '', $dir );
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
        $module = $registry->get_module( $slug, 'monetization_platform' );
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
    ];
}

// Sort installed integrations by name ASC.
usort( $integrations, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Monetization Platforms</h2>
            <p class="mt-1 text-sm text-gray-500">Manage your monetization platform integrations.</p>
        </div>
        <div class="relative" style="width:220px;">
            <input type="text" id="optee-mp-search" class="optee-input" placeholder="Search..." style="padding-left:2.25rem;padding-right:0.75rem;">
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
        $full_slug = 'optee-monetization-' . $item['slug'];
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
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-2">No Monetization Platforms Installed</h3>
            <p class="text-sm text-gray-500 mx-auto">Install a monetization module plugin to track earnings.</p>
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
            <div class="optee-card relative flex flex-col items-center text-center p-6 optee-mp-card" data-name="<?php echo esc_attr( strtolower( $integration['name'] ) ); ?>">
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
                <div class="flex items-center justify-center w-full mb-4" style="height:80px;">
                    <?php if ( $integration['logo_url'] ) : ?>
                        <img src="<?php echo esc_url( $integration['logo_url'] ); ?>"
                             alt="<?php echo esc_attr( $integration['name'] ); ?>"
                             style="max-height:60px;max-width:180px;width:auto;">
                    <?php else : ?>
                        <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center text-gray-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        </div>
                    <?php endif; ?>
                </div>
                <h3 class="text-lg font-semibold text-gray-900"><?php echo esc_html( $integration['name'] ); ?></h3>
                <?php
                // Surface a partner promo where the decision is actually made —
                // before signup and before funding — instead of only on the
                // Bonuses page, which people reach once they already have an
                // account. Renders nothing when the partner has no live offer.
                $bonus = \Optee\Plugin\Bonuses::for_slug( $integration['slug'] ?? '', 'monetization_platform' );
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
                <?php
                $full_slug   = 'optee-monetization-' . $integration['slug'];
                $has_update  = ! empty( $updates[ $full_slug ]['update_available'] );
                $new_version = $updates[ $full_slug ]['latest_version'] ?? '';
                ?>
                <div class="mt-auto pt-4 flex items-center gap-2">
                    <?php if ( $integration['is_active'] ) : ?>
                        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'monetization-platforms', 'configure', [ 'slug' => $integration['slug'] ] ) ); ?>"
                           class="optee-btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                            Configure
                        </a>
                    <?php else : ?>
                        <button type="button"
                                class="optee-btn-success optee-activate-plugin"
                                data-plugin="<?php echo esc_attr( $integration['plugin_file'] ); ?>"
                                data-configure-url="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'monetization-platforms', 'configure', [ 'slug' => $integration['slug'] ] ) ); ?>">
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
            $card_class   = 'optee-mp-card';
            $placeholder['slug'] = $placeholder['full_slug'];
            include OPTEE_PLUGIN_DIR . 'templates/partials/install-plugin-card.php';
        endforeach; ?>

        <?php foreach ( $coming_soon as $placeholder ) : ?>
            <div class="optee-card relative flex flex-col items-center text-center p-6 optee-mp-card" data-name="<?php echo esc_attr( strtolower( $placeholder['name'] ) ); ?>" style="opacity:0.6;">
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
                    <img src="<?php echo esc_url( $placeholder['logo_url'] ); ?>"
                         alt="<?php echo esc_attr( $placeholder['name'] ); ?>"
                         style="max-height:60px;max-width:180px;width:auto;">
                </div>
                <h3 class="text-lg font-semibold text-gray-900"><?php echo esc_html( $placeholder['name'] ); ?></h3>
                <?php
                // Surface a partner promo where the decision is actually made —
                // before signup and before funding — instead of only on the
                // Bonuses page, which people reach once they already have an
                // account. Renders nothing when the partner has no live offer.
                $bonus = \Optee\Plugin\Bonuses::for_slug( $placeholder['slug'] ?? '', 'monetization_platform' );
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
    $('#optee-mp-search').on('input', function() {
        var query = $(this).val().toLowerCase();
        $('.optee-mp-card').each(function() {
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
