<?php

/**
 * Generic clean-URL router for the remaining mirror CONTENT pages — the ones
 * with no WooCommerce/WP-page equivalent: news articles (NewDetails/*),
 * compliance docs (CableComplianceDetails/*), featured/"highlight" collections
 * (product/<n>), and the root SEO/landing pages (best-*, china-*, control-*,
 * outdoor-*, policy, SEARCH).
 *
 * They are reached in the original markup by RELATIVE links (e.g.
 * NewDetails/123.html) that resolve via <base> to the raw static theme files.
 * Here we serve each at a clean WP URL  /m/<path>/  (rendered through
 * mirror_html + chrome + finalize, exactly like the managed pages) and rewrite
 * those links to it (sanyuan_rewrite_content_links, called from finalize).
 *
 * Why /m/ and a registered rewrite rule: only WP-registered URLs reach
 * WordPress on this host (arbitrary clean paths and .html are served by nginx
 * directly) — see [[woo-product-detail]]. A registered rule resolves the URL to
 * a query var so the request reaches template_redirect.
 */

namespace App;

const SANYUAN_MIRROR_QV = 'sanyuan_mirror';

/** Nav/mirror pages already served at their own clean WP URLs — never /m/. */
function sanyuan_nav_files(): array
{
    return [
        'index', 'about', 'about-acf', 'product', 'news', 'Support', 'concact',
        'concactd41d', 'ESG', 'CableCompliance', 'CableLabOverview', 'CableTestingInspection',
    ];
}

/**
 * Set of content relpaths (without .html) eligible for /m/ routing:
 * the content sub-directories + the root SEO/landing pages (minus nav pages,
 * minus the big product_Details/ & product_list/ trees which have their own
 * WC routes). Built once per request.
 */
function sanyuan_content_relpaths(): array
{
    static $set = null;
    if ($set !== null) {
        return $set;
    }
    $set = [];
    $root = untrailingslashit(get_theme_file_path('public/site'));
    $nav = array_flip(sanyuan_nav_files());

    // Many mirror files are tiny WAF "403 Forbidden" stubs (HTTrack was blocked
    // mirroring them) — real pages are >100KB. Gate on size so only genuine
    // content routes; stub links are left alone (they point at junk anyway).
    $real = fn (string $f): bool => @filesize($f) > 50000;

    foreach (glob($root . '/*.html') ?: [] as $f) {
        $name = basename($f, '.html');
        if (! isset($nav[$name]) && $real($f)) {
            $set[$name] = true;
        }
    }
    foreach (['NewDetails', 'CableComplianceDetails', 'product'] as $dir) {
        foreach (glob($root . '/' . $dir . '/*.html') ?: [] as $f) {
            if ($real($f)) {
                $set[$dir . '/' . basename($f, '.html')] = true;
            }
        }
    }
    return $set;
}

/**
 * Rewrite remaining relative links to content mirror files
 * (href="(../)*<relpath>.html") to their clean /m/<relpath>/ URL. Runs in
 * finalize AFTER the product/category/nav rewrites, so by here any leftover
 * relative .html link is a content page. Imported sets only — unknown paths are
 * left untouched.
 */
function sanyuan_rewrite_content_links(string $html): string
{
    $set = sanyuan_content_relpaths();
    if (! $set) {
        return $html;
    }
    return preg_replace_callback(
        '~href="((?:\.\./)*)([^":?#]+?)\.html"~i',
        function ($m) use ($set) {
            $path = $m[2]; // root-based canonical (the content dirs live at root)
            return isset($set[$path]) ? 'href="' . esc_url(home_url('/m/' . $path . '/')) . '"' : $m[0];
        },
        $html
    ) ?? $html;
}

/* Register the /m/<path>/ rewrite rule + query var. */
add_action('init', function () {
    add_rewrite_tag('%' . SANYUAN_MIRROR_QV . '%', '(.+)');
    // Đa ngôn ngữ: bản có tiền tố /<lang>/m/<path>/ (vd /zh/m/...). Đặt TRƯỚC rule
    // không tiền tố; 'lang' báo Polylang ngôn ngữ của request.
    add_rewrite_rule('^([a-z]{2})/m/(.+?)/?$', 'index.php?lang=$matches[1]&' . SANYUAN_MIRROR_QV . '=$matches[2]', 'top');
    add_rewrite_rule('^m/(.+?)/?$', 'index.php?' . SANYUAN_MIRROR_QV . '=$matches[1]', 'top');

    // Cờ do app/setup-i18n.php đặt: flush MỘT LẦN ở request web (lúc này mọi rule
    // — Polylang + /m/ của theme — đã đăng ký đủ), rồi xoá cờ.
    if (get_option('sanyuan_rewrite_flush')) {
        flush_rewrite_rules(false);
        delete_option('sanyuan_rewrite_flush');
    }
});
add_filter('query_vars', function ($vars) {
    $vars[] = SANYUAN_MIRROR_QV;
    return $vars;
});

/* Render a content mirror page at /m/<path>/. */
add_action('template_redirect', function () {
    $path = (string) get_query_var(SANYUAN_MIRROR_QV);
    if ($path === '') {
        return;
    }
    $path = trim($path, '/');

    // Hard safety: no traversal, simple charset only, must be a known content page.
    if ($path === '' || strpos($path, '..') !== false
        || ! preg_match('~^[A-Za-z0-9_/.-]+$~', $path)
        || ! isset(sanyuan_content_relpaths()[$path])) {
        return; // -> WordPress 404
    }

    $file = $path . '.html';
    $html = mirror_html($file);
    if ($html === '') {
        return;
    }
    if (function_exists('get_field')) {
        $html = inject_header($html);
        $html = inject_footer($html);
        $html = inject_chrome($html);
    }
    $html = sanyuan_finalize_links($html);

    nocache_headers();
    echo $html;
    exit;
}, -1);
