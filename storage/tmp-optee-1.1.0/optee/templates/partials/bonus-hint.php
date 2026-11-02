<?php
/**
 * Contextual partner-bonus hint.
 *
 * Surfaces an offer where the decision is actually made — on an integration card
 * or its configure page — rather than only on the Bonuses page, which people
 * reach after they have already signed up and funded an account.
 *
 * Two variants, because the two hosts have very different room:
 *   'badge' — a pill for the integration card grid. Cards are narrow and already
 *             carry a logo, name, version and buttons, so this stays to one line
 *             and defers the detail to a click.
 *   'strip' — a full-width bar for a configure page, where there is space to show
 *             the terms and the signup link up front.
 *
 * Expects (set by the including template):
 *   $bonus   array  A visible entry from Optee\Plugin\Bonuses.
 *   $variant string 'badge' (default) or 'strip'.
 *
 * Copy-to-clipboard is handled by the delegated `.optee-bonus-copy` handler that
 * ships with the Bonuses page script; `optee-bonus-hint.js` carries the same
 * behaviour for pages that do not load it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( empty( $bonus ) || ! is_array( $bonus ) ) {
    return;
}

$variant = $variant ?? 'badge';
$code    = trim( (string) ( $bonus['promo_code'] ?? '' ) );
$desc    = (string) ( $bonus['description'] ?? '' );
$note    = (string) ( $bonus['note'] ?? '' );
$signup  = (string) ( $bonus['signup_url'] ?? '' );
$name    = (string) ( $bonus['name'] ?? '' );

// A gift glyph reads as "offer" faster than any wording that would fit here.
$gift_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>';

if ( $variant === 'strip' ) : ?>
    <div class="optee-bonus-strip" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#fffdf5;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:16px;">
        <span style="display:inline-flex;align-items:center;gap:6px;color:#b45309;font-size:12px;font-weight:600;white-space:nowrap;">
            <?php echo $gift_svg; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup above ?>
            Bonus available
        </span>
        <span style="flex:1;min-width:180px;font-size:12px;color:#6b7280;line-height:1.5;">
            <?php echo esc_html( $desc ); ?>
            <?php if ( $note ) : ?>
                <span style="color:#9ca3af;"><?php echo esc_html( $note ); ?></span>
            <?php endif; ?>
        </span>
        <?php if ( $code !== '' ) : ?>
            <span style="display:inline-flex;align-items:center;gap:6px;">
                <code style="background:#fff;border:1px dashed #d1d5db;border-radius:6px;padding:4px 10px;font-size:13px;font-weight:600;color:#111827;white-space:nowrap;"><?php echo esc_html( $code ); ?></code>
                <button type="button" class="optee-bonus-copy" data-code="<?php echo esc_attr( $code ); ?>" title="Copy promo code"
                        style="background:none;border:1px solid #e5e7eb;border-radius:6px;padding:5px 7px;cursor:pointer;color:#6b7280;display:flex;align-items:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                </button>
            </span>
        <?php else : ?>
            <span style="font-size:11px;color:#9ca3af;font-style:italic;white-space:nowrap;">No code needed — via sign-up link</span>
        <?php endif; ?>
        <?php if ( $signup ) : ?>
            <a href="<?php echo esc_url( $signup ); ?>" target="_blank" rel="noopener"
               class="optee-btn-secondary optee-btn-sm" style="font-size:12px;white-space:nowrap;">Sign Up</a>
        <?php endif; ?>
    </div>
<?php else : ?>
    <?php
    // Everything the popover needs travels on the element, so the card grid needs
    // no per-card script and no lookup back into PHP.
    ?>
    <button type="button"
            class="optee-bonus-badge"
            data-name="<?php echo esc_attr( $name ); ?>"
            data-code="<?php echo esc_attr( $code ); ?>"
            data-desc="<?php echo esc_attr( $desc ); ?>"
            data-note="<?php echo esc_attr( $note ); ?>"
            data-signup="<?php echo esc_attr( $signup ); ?>"
            title="<?php echo esc_attr( $desc ); ?>"
            style="display:inline-flex;align-items:center;gap:5px;background:#fffbeb;border:1px solid #fde68a;color:#b45309;border-radius:9999px;padding:2px 9px;font-size:11px;font-weight:600;cursor:pointer;line-height:1.6;max-width:100%;">
        <?php echo $gift_svg; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup above ?>
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $code !== '' ? $code : 'Bonus' ); ?></span>
    </button>
<?php endif; ?>
