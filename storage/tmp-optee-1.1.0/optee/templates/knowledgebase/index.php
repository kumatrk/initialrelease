<?php
/**
 * Knowledgebase — curated affiliate marketing articles.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require __DIR__ . '/articles-data.php';

// Separate pinned and unpinned articles.
$pinned  = array_filter( $articles, fn( $a ) => ! empty( $a['pinned'] ) );
// TMP: Hide external articles (no slug = external). Remove to show all categories.
$regular = array_filter( $articles, fn( $a ) => empty( $a['pinned'] ) && ! empty( $a['slug'] ) );

// Group regular articles by category (preserving order).
$categories = [];
foreach ( $regular as $article ) {
    $categories[ $article['category'] ][] = $article;
}
?>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Knowledgebase</h2>
            <p class="mt-1 text-sm text-gray-500">Guides, tutorials, and best practices for affiliate campaign optimization.</p>
        </div>
        <div style="width: 280px;">
            <input type="text" id="optee-kb-search" class="optee-input" placeholder="Search articles...">
        </div>
    </div>

    <?php if ( $pinned ) : ?>
    <!-- Pinned — How to use Optee -->
    <?php
    $pinned_categories = [];
    foreach ( $pinned as $a ) {
        $pinned_categories[ $a['category'] ][] = $a;
    }
    ?>
    <div id="optee-kb-pinned">
        <?php foreach ( $pinned_categories as $cat_name => $cat_items ) : ?>
        <div class="optee-card" style="padding:16px 20px;border-left:3px solid #7c3aed;width:fit-content;min-width:320px;">
            <div class="flex items-center gap-2 mb-3">
                <span class="optee-kb-badge-pinned">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/></svg>
                    <?php echo esc_html( $cat_name ); ?>
                </span>
            </div>
            <div style="display:flex;flex-direction:column;">
                <?php foreach ( $cat_items as $article ) :
                    $is_local = ! empty( $article['slug'] );
                    $href = $is_local
                        ? esc_url( \Optee\Plugin\Shortcode::get_url( 'knowledgebase', 'article' ) . '&slug=' . urlencode( $article['slug'] ) )
                        : esc_url( $article['url'] );
                    $target = $is_local ? '' : ' target="_blank" rel="noopener noreferrer"';
                ?>
                <div data-title="<?php echo esc_attr( strtolower( $article['title'] ) ); ?>" style="padding:6px 0;">
                    <a href="<?php echo $href; ?>"<?php echo $target; ?> style="display:flex;align-items:center;gap:8px;text-decoration:none;color:#111827;font-size:14px;font-weight:500;" class="hover:text-brand-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="9 18 15 12 9 6"/></svg>
                        <span><?php echo esc_html( $article['title'] ); ?></span>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Category Sections -->
    <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:16px;">
    <?php foreach ( $categories as $category_name => $cat_articles ) : ?>
    <div class="optee-kb-section">
        <div class="optee-card" style="padding:16px 20px;height:100%;">
            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3"><?php echo esc_html( $category_name ); ?></h3>
            <ul style="list-style:none;margin:0;padding:0;">
                <?php foreach ( $cat_articles as $article ) : ?>
                <?php
                    $is_local = ! empty( $article['slug'] );
                    $href = $is_local
                        ? esc_url( \Optee\Plugin\Shortcode::get_url( 'knowledgebase', 'article' ) . '&slug=' . urlencode( $article['slug'] ) )
                        : esc_url( $article['url'] );
                    $target = $is_local ? '' : ' target="_blank" rel="noopener noreferrer"';
                    $ext_icon = $is_local ? '' : '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>';
                ?>
                <li class="optee-kb-item" data-title="<?php echo esc_attr( strtolower( $article['title'] ) ); ?>" style="padding:6px 0;">
                    <a href="<?php echo $href; ?>"<?php echo $target; ?> style="display:flex;align-items:center;gap:8px;text-decoration:none;color:#111827;font-size:14px;font-weight:500;" class="hover:text-brand-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="9 18 15 12 9 6"/></svg>
                        <span><?php echo esc_html( $article['title'] ); ?></span>
                        <?php echo $ext_icon; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- No Results -->
    <div id="optee-kb-no-results" class="optee-card text-center py-8" style="display:none;">
        <p class="text-sm text-gray-500">No articles match your search.</p>
    </div>
</div>

<script>
jQuery(function($) {
    var $search   = $('#optee-kb-search');
    var $cards    = $('.optee-kb-card');
    var $items    = $('.optee-kb-item');
    var $sections = $('.optee-kb-section');
    var $pinned   = $('#optee-kb-pinned');
    var $noRes    = $('#optee-kb-no-results');

    $search.on('input', function() {
        var q = this.value.toLowerCase().trim();
        var visible = 0;

        // Show all containers first so :visible checks work on children.
        $sections.show();
        $pinned.show();

        // Filter pinned cards.
        $cards.each(function() {
            var title = $(this).data('title') || '';
            var match = !q || title.indexOf(q) !== -1;
            $(this).toggle(match);
            if (match) visible++;
        });

        // Filter list items in category cards.
        $items.each(function() {
            var title = $(this).data('title') || '';
            var match = !q || title.indexOf(q) !== -1;
            $(this).toggle(match);
            if (match) visible++;
        });

        // Hide category sections where all items are hidden.
        $sections.each(function() {
            $(this).toggle($(this).find('.optee-kb-item:visible').length > 0);
        });

        // Hide pinned section if all pinned cards hidden.
        if ($pinned.length) {
            $pinned.toggle($pinned.find('.optee-kb-card:visible').length > 0);
        }

        $noRes.toggle(visible === 0);
    });
});
</script>
