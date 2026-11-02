<?php
/**
 * Knowledgebase — Single article view (local articles).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require __DIR__ . '/articles-data.php';

$slug    = isset( $_GET['slug'] ) ? sanitize_key( $_GET['slug'] ) : '';
$article = null;

foreach ( $articles as $a ) {
    if ( ! empty( $a['slug'] ) && $a['slug'] === $slug ) {
        $article = $a;
        break;
    }
}

$back_url = \Optee\Plugin\Shortcode::get_url( 'knowledgebase' );
?>
<div style="max-width:min(800px,100%);">
    <div class="mb-4">
        <a href="<?php echo esc_url( $back_url ); ?>"
           class="text-sm text-gray-500 hover:text-gray-900 transition-colors">&larr; Back to Knowledgebase</a>
    </div>

    <?php if ( ! $article ) : ?>
        <div class="optee-card">
            <p class="text-gray-500 text-center py-8">Article not found.</p>
        </div>
    <?php else : ?>
        <div class="optee-card">
            <div class="mb-4">
                <span class="optee-badge-gray"><?php echo esc_html( $article['category'] ); ?></span>
            </div>
            <h2 class="text-xl font-bold text-gray-900 mb-4"><?php echo esc_html( $article['title'] ); ?></h2>
            <div class="optee-kb-article-content">
                <?php echo wp_kses_post( $article['content'] ); ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.optee-kb-article-content h3 {
    font-size: 16px;
    font-weight: 600;
    color: #111827;
    margin-top: 24px;
    margin-bottom: 8px;
}
.optee-kb-article-content p {
    font-size: 14px;
    color: #374151;
    line-height: 1.7;
    margin: 10px 0;
}
.optee-kb-article-content ul {
    font-size: 14px;
    color: #374151;
    line-height: 1.7;
    margin: 8px 0;
    padding-left: 24px;
    list-style-type: disc;
}
.optee-kb-article-content ol {
    font-size: 14px;
    color: #374151;
    line-height: 1.7;
    margin: 8px 0;
    padding-left: 24px;
    list-style-type: decimal;
}
.optee-kb-article-content li {
    margin: 4px 0;
}
.optee-kb-article-content code {
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 13px;
    font-family: monospace;
}
.optee-kb-article-content strong {
    font-weight: 600;
    color: #111827;
}
</style>
