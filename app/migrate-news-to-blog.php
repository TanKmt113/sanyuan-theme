<?php

/**
 * One-off: migrate the legacy `sy_news` custom-post-type articles into the
 * standard WordPress blog (`post` type) filed under the "News & Events"
 * category, in the default Polylang language. Idempotent. CLI only.
 *
 *   php wp-content/themes/sanyuan-theme/app/migrate-news-to-blog.php
 *
 * Posts are looked up by raw post_type via $wpdb (the `sy_news` type is no
 * longer registered, so get_posts() wouldn't find them). Content, excerpt,
 * date, thumbnail and every meta (news_link / news_image / _sanyuan_news_src)
 * are preserved — only post_type + category + language change.
 *
 * NOT auto-loaded by functions.php (it is not in the bootstrap list); run it
 * explicitly from the CLI.
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}

$dir = __DIR__;
while ($dir !== '/' && ! file_exists($dir . '/wp-load.php')) {
    $dir = dirname($dir);
}
if (! file_exists($dir . '/wp-load.php')) {
    exit("wp-load.php not found\n");
}

define('WP_USE_THEMES', false);
require $dir . '/wp-load.php';
require __DIR__ . '/import-news.php';

global $wpdb;
$ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
    'sy_news'
));

echo 'Found ' . count($ids) . " legacy sy_news post(s)\n";

$catId = \App\sanyuan_news_ensure_category();
$lang  = function_exists('pll_default_language') ? (pll_default_language() ?: 'en') : 'en';
echo "News & Events category term id = {$catId}, language = {$lang}\n";
echo str_repeat('-', 60) . "\n";

$done = 0;
foreach ($ids as $id) {
    $id = (int) $id;

    $r = wp_update_post(['ID' => $id, 'post_type' => 'post'], true);
    if (is_wp_error($r)) {
        echo "  #{$id}  ERROR: " . $r->get_error_message() . "\n";
        continue;
    }
    if ($catId) {
        wp_set_post_categories($id, [$catId], false);
    }
    if (function_exists('pll_set_post_language')) {
        pll_set_post_language($id, $lang);
    }

    $done++;
    echo "  #{$id}  -> post | category set | lang={$lang} : " . get_the_title($id) . "\n";
}

echo str_repeat('-', 60) . "\n";
echo "Migrated {$done} post(s).\n";

// The sy_news rewrite slug is gone — rebuild rules on the next web request.
update_option('sanyuan_rewrite_flush', 1);
