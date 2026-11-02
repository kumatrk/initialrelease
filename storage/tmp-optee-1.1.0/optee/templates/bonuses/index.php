<?php
/**
 * Bonuses & Promo Codes page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Visibility rules (placeholder codes, retired partners) live in the Bonuses
// class so this page and the contextual hints on integration cards can never
// disagree about which offers are real.
$bonuses = \Optee\Plugin\Bonuses::visible();
usort( $bonuses, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
$plugins_url = WP_PLUGIN_URL . '/';
?>
<style>
/* Phones: one card per row (the fixed-width logos overflow a 2-col grid on narrow
   screens) and let the name + type badge wrap so the badge is never clipped by the
   card's overflow:hidden. */
@media (max-width:640px){
    #optee-bonuses-grid{grid-template-columns:1fr!important;}
    .optee-bonus-head{flex-wrap:wrap;}
}
</style>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Bonuses & Promo Codes</h2>
            <p class="mt-1 text-sm text-gray-500">Exclusive deals and promo codes from our partners.</p>
        </div>
        <div class="relative" style="width:220px;">
            <input type="text" id="optee-bonus-search" class="optee-input" placeholder="Search..." style="padding-left:2.25rem;padding-right:0.75rem;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute text-gray-400" style="left:0.65rem;top:50%;transform:translateY(-50%);display:block;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        </div>
    </div>

    <?php if ( empty( $bonuses ) ) : ?>
        <div class="optee-card">
            <p class="text-gray-400 text-center py-8">No bonuses available at the moment.</p>
        </div>
    <?php else : ?>
        <div id="optee-bonuses-grid" style="display:grid;grid-template-columns:repeat(2, 1fr);gap:16px;">
            <?php foreach ( $bonuses as $bonus ) : ?>
                <div class="optee-card optee-bonus-card" data-name="<?php echo esc_attr( strtolower( $bonus['name'] . ' ' . ( $bonus['type'] ?? '' ) . ' ' . ( $bonus['promo_code'] ?? '' ) ) ); ?>" style="padding:0;overflow:hidden;display:flex;flex-direction:column;min-height:148px;">
                    <div style="display:flex;align-items:center;gap:16px;padding:18px 20px;flex:1;">
                        <!-- Logo -->
                        <?php if ( ! empty( $bonus['logo'] ) ) : ?>
                            <div style="flex-shrink:0;width:140px;display:flex;align-items:center;justify-content:center;<?php if ( ! empty( $bonus['logo_bg'] ) ) echo 'border-radius:6px;background:' . esc_attr( $bonus['logo_bg'] ) . ';padding:10px 14px;'; ?>">
                                <?php
                                    $logo_src = $bonus['logo'];
                                    if ( $logo_src && ! str_starts_with( $logo_src, 'http' ) ) {
                                        $logo_src = $plugins_url . $logo_src;
                                    }
                                    $img_max = ! empty( $bonus['logo_bg'] ) ? '112px' : '140px';
                                ?>
                                <img src="<?php echo esc_url( $logo_src ); ?>" alt="<?php echo esc_attr( $bonus['name'] ); ?>"
                                     style="max-width:<?php echo esc_attr( $img_max ); ?>;max-height:50px;object-fit:contain;<?php if ( ! empty( $bonus['logo_rounded'] ) ) echo 'border-radius:6px;'; ?>">
                            </div>
                        <?php endif; ?>

                        <!-- Info + Promo -->
                        <div style="flex:1;min-width:0;">
                            <div class="optee-bonus-head" style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                <h3 style="font-size:15px;font-weight:700;color:#111827;margin:0;"><?php echo esc_html( $bonus['name'] ); ?></h3>
                                <?php if ( ! empty( $bonus['type'] ) ) : ?>
                                    <span class="optee-badge-blue" style="font-size:10px;padding:1px 8px;"><?php echo esc_html( $bonus['type'] ); ?></span>
                                <?php endif; ?>
                            </div>
                            <p style="font-size:12px;color:#6b7280;margin:0 0 10px;line-height:1.5;"><?php echo esc_html( $bonus['description'] ); ?></p>
                            <?php if ( ! empty( $bonus['promo_code'] ) ) : ?>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <code style="background:#f3f4f6;border:1px dashed #d1d5db;border-radius:6px;padding:6px 14px;font-size:14px;font-weight:600;color:#111827;letter-spacing:0.02em;white-space:nowrap;"><?php echo esc_html( $bonus['promo_code'] ); ?></code>
                                    <button class="optee-bonus-copy" data-code="<?php echo esc_attr( $bonus['promo_code'] ); ?>"
                                            title="Copy promo code"
                                            style="background:none;border:1px solid #e5e7eb;border-radius:6px;padding:6px 8px;cursor:pointer;color:#6b7280;display:flex;align-items:center;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                                    </button>
                                </div>
                            <?php else : ?>
                                <div style="display:inline-flex;align-items:center;gap:6px;background:#f9fafb;border:1px dashed #e5e7eb;border-radius:6px;padding:6px 12px;font-size:12px;color:#9ca3af;font-style:italic;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                                    No code needed — discount via sign-up link
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Footer with note + signup link -->
                    <?php if ( ! empty( $bonus['note'] ) || ! empty( $bonus['signup_url'] ) ) : ?>
                        <div style="background:#f9fafb;border-top:1px solid #f3f4f6;padding:10px 20px;display:flex;align-items:center;justify-content:space-between;margin-top:auto;">
                            <?php if ( ! empty( $bonus['note'] ) ) : ?>
                                <p style="font-size:11px;color:#9ca3af;margin:0;"><?php echo esc_html( $bonus['note'] ); ?></p>
                            <?php else : ?>
                                <span></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $bonus['signup_url'] ) ) : ?>
                                <a href="<?php echo esc_url( $bonus['signup_url'] ); ?>" target="_blank" rel="noopener"
                                   class="optee-btn-primary optee-btn-sm" style="font-size:12px;white-space:nowrap;">
                                    Sign Up
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left:4px;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
jQuery(function($) {
    $('#optee-bonus-search').on('input', function() {
        var q = $(this).val().toLowerCase();
        $('.optee-bonus-card').each(function() {
            $(this).toggle($(this).data('name').indexOf(q) !== -1);
        });
    });

    // Promo-code copying is handled globally by optee-bonus-hint.js — the same
    // `.optee-bonus-copy` markup is now used by the hints on integration cards
    // and configure pages, so keeping a second binding here would fire twice.
});
</script>
