<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Partner bonuses and promo codes.
 *
 * Backed by templates/bonuses/bonuses.json. This class exists so the visibility
 * rules live in ONE place: the Bonuses page used to filter inline, and once the
 * same offers started appearing on integration cards and configure pages, two
 * copies of "which bonuses are real" would have drifted immediately — the first
 * symptom being a card advertising a literal `??????` as its promo code.
 *
 * Bonuses are matched to integrations by MODULE slug (`adcash`, `cpicash`), the
 * same key ModuleRegistry uses and the same value the campaign form already puts
 * in its <option value>. A bonus may map to several slugs: partners like
 * RollerAds and Zeydoo ship as two modules (advertiser + publisher side) and the
 * one offer is relevant to both.
 */
class Bonuses {

    /** Placeholder used in bonuses.json for a partner whose code isn't agreed yet. */
    private const PLACEHOLDER_CODE = '??????';

    /**
     * Partners hidden from the Bonuses page despite having an entry.
     *
     * Kept as data rather than deleted from the JSON so the negotiated terms and
     * signup links survive for when the deal is back on.
     */
    private const HIDDEN = [ 'FunnelFlux', 'BeMob', 'Push.House' ];

    /** @var array<int, array>|null Parsed file, memoised per request. */
    private static ?array $cache = null;

    /**
     * Every entry in the file, unfiltered. Includes placeholder and hidden ones —
     * callers almost always want visible() instead.
     *
     * @return array<int, array>
     */
    public static function all(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }

        $path = OPTEE_PLUGIN_DIR . 'templates/bonuses/bonuses.json';
        if ( ! file_exists( $path ) ) {
            self::$cache = [];
            return self::$cache;
        }

        $decoded = json_decode( (string) file_get_contents( $path ), true );
        self::$cache = is_array( $decoded ) ? $decoded : [];

        return self::$cache;
    }

    /**
     * Entries fit to show a user: a real offer from a partner we still promote.
     *
     * An entry with no promo code is still visible — several partners give the
     * discount through the signup link instead ("No code needed"), and those are
     * as real as the coded ones.
     *
     * @return array<int, array>
     */
    public static function visible(): array {
        return array_values( array_filter( self::all(), static function ( array $b ): bool {
            if ( ( $b['promo_code'] ?? '' ) === self::PLACEHOLDER_CODE ) {
                return false;
            }
            return ! in_array( $b['name'] ?? '', self::HIDDEN, true );
        } ) );
    }

    /**
     * The visible bonus for a module slug, or null.
     *
     * @param string      $slug Module slug, e.g. 'adcash', 'cpicash', 'rollerads-publisher'.
     * @param string|null $type Optional module type ('traffic_source', 'tracker', …) to
     *                          disambiguate a partner that ships on both sides. Ignored
     *                          when the entry does not declare a type of its own.
     */
    public static function for_slug( string $slug, ?string $type = null ): ?array {
        if ( $slug === '' ) {
            return null;
        }

        foreach ( self::visible() as $bonus ) {
            $slugs = array_map( 'strval', (array) ( $bonus['slugs'] ?? [] ) );
            if ( ! in_array( $slug, $slugs, true ) ) {
                continue;
            }

            if ( $type !== null && ! self::type_matches( $bonus, $type ) ) {
                continue;
            }

            return $bonus;
        }

        return null;
    }

    /**
     * Visible bonuses keyed by slug, for handing to JavaScript in one go.
     *
     * The whole file is 21 entries, so shipping the map with the page beats an
     * AJAX round trip per card.
     *
     * @param string[] $applies_to Restrict to offers claimable in a given context —
     *                             see js_map() for why the campaign form filters.
     * @return array<string, array>
     */
    public static function by_slug( array $applies_to = [] ): array {
        $out = [];
        foreach ( self::visible() as $bonus ) {
            if ( $applies_to && ! in_array( self::applies_to( $bonus ), $applies_to, true ) ) {
                continue;
            }
            foreach ( (array) ( $bonus['slugs'] ?? [] ) as $slug ) {
                $out[ (string) $slug ] = $bonus;
            }
        }
        return $out;
    }

    /**
     * When an offer can be claimed: 'signup', 'topup', or 'both'.
     *
     * Entries written before this field existed are treated as 'both', so an
     * unclassified bonus errs towards being shown rather than silently lost.
     */
    public static function applies_to( array $bonus ): string {
        $value = (string) ( $bonus['applies_to'] ?? 'both' );
        return in_array( $value, [ 'signup', 'topup', 'both' ], true ) ? $value : 'both';
    }

    /**
     * Compact slug-keyed payload for the browser.
     *
     * Only the fields the toast renders, so credentials-adjacent noise and the
     * logo paths never leave PHP.
     *
     * The campaign form passes ['topup','both'] deliberately. By the time someone
     * is building a campaign they already have the account, so a registration-only
     * discount is pure interruption — while a deposit or invoice bonus is exactly
     * what they are about to need. Without this the form would fire a toast for
     * every partner and quickly become wallpaper.
     *
     * @param string[] $applies_to
     * @return array<string, array{name:string,code:string,desc:string,note:string,url:string}>
     */
    public static function js_map( array $applies_to = [] ): array {
        $out = [];
        foreach ( self::by_slug( $applies_to ) as $slug => $bonus ) {
            $out[ $slug ] = [
                'name' => (string) ( $bonus['name'] ?? '' ),
                'code' => (string) ( $bonus['promo_code'] ?? '' ),
                'desc' => (string) ( $bonus['description'] ?? '' ),
                'note' => (string) ( $bonus['note'] ?? '' ),
                'url'  => (string) ( $bonus['signup_url'] ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Short label for a card badge — the code itself when there is one, otherwise
     * a hint that the discount rides on the signup link.
     */
    public static function short_label( array $bonus ): string {
        $code = trim( (string) ( $bonus['promo_code'] ?? '' ) );
        return $code !== '' ? $code : 'Bonus';
    }

    /**
     * Does a bonus entry's human-readable type match a module type constant?
     *
     * bonuses.json predates this feature and stores display strings ("Traffic
     * Source"), while modules use snake_case. Anything unrecognised — "Forum",
     * say — matches nothing, which is correct: those partners have no module.
     */
    private static function type_matches( array $bonus, string $type ): bool {
        $map = [
            'Traffic Source'    => [ 'traffic_source' ],
            'Tracker'           => [ 'tracker' ],
            'Affiliate Network' => [ 'affiliate_network' ],
            // Publisher-side offers sit under the advertiser type in the file, so a
            // monetization module is allowed to match either label.
            'Monetization'      => [ 'monetization_platform' ],
        ];

        $declared = (string) ( $bonus['type'] ?? '' );
        if ( ! isset( $map[ $declared ] ) ) {
            return false;
        }

        if ( in_array( $type, $map[ $declared ], true ) ) {
            return true;
        }

        // A partner that ships an advertiser and a publisher module shares one
        // offer; the entry is typed for the advertiser side but the publisher
        // module should still surface it.
        return $type === 'monetization_platform' && $declared === 'Traffic Source';
    }
}
