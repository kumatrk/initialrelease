<?php
/**
 * Install plugin card — shown for plugins available on API but not installed locally.
 *
 * Variables expected: $placeholder (array with slug, name, logo_url, desc, signup_url, guide_url, dark_bg),
 *                     $card_class (CSS class for the card, e.g., 'optee-ts-card'),
 *                     $module_route (e.g., 'traffic-sources')
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="optee-card relative flex flex-col items-center text-center p-6 <?php echo esc_attr( $card_class ); ?>" data-name="<?php echo esc_attr( strtolower( $placeholder['name'] ) ); ?>" style="opacity:0.55;">
    <!-- Top row: Guide link (left) + Badge (right) -->
    <div class="absolute flex items-center justify-between" style="top:0.75rem;left:0.75rem;right:0.75rem;">
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
        <span class="optee-badge-blue">Available</span>
    </div>

    <!-- Logo -->
    <div class="flex items-center justify-center w-full mb-4" style="height:80px;">
        <?php if ( ! empty( $placeholder['logo_url'] ) ) : ?>
            <img src="<?php echo esc_url( $placeholder['logo_url'] ); ?>"
                 alt="<?php echo esc_attr( $placeholder['name'] ); ?>"
                 style="max-height:60px;max-width:180px;width:auto;<?php echo ! empty( $placeholder['dark_bg'] ) ? 'background:#1a1a2e;border-radius:6px;padding:8px 12px;' : ''; ?>">
        <?php else : ?>
            <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
            </div>
        <?php endif; ?>
    </div>

    <!-- Name & description -->
    <h3 class="text-lg font-semibold text-gray-900"><?php echo esc_html( $placeholder['name'] ); ?></h3>
    <?php if ( ! empty( $placeholder['desc'] ) ) : ?>
        <p class="text-sm text-gray-500 mt-1" style="max-width:340px;">
            <?php echo esc_html( $placeholder['desc'] ); ?>
            <?php if ( ! empty( $placeholder['signup_url'] ) ) : ?>
                <a href="<?php echo esc_url( $placeholder['signup_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:text-brand-700" style="white-space:nowrap;">Sign Up</a>
            <?php endif; ?>
        </p>
    <?php else : ?>
        <p class="text-xs text-gray-400 mt-1">Ready to install</p>
    <?php endif; ?>

    <!-- Install button -->
    <div class="mt-auto pt-4">
        <button type="button"
                class="optee-btn-primary optee-install-from-api"
                data-slug="<?php echo esc_attr( $placeholder['slug'] ); ?>"
                data-name="<?php echo esc_attr( $placeholder['name'] ); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
            Install
        </button>
    </div>
</div>
