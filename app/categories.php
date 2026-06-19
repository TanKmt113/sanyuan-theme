<?php

/**
 * WooCommerce product categories — SANYUAN mirror integration.
 *
 * The original site's category tree (the mega-menu `e_categoryB` panel) is
 * imported into a real, hierarchical `product_cat` taxonomy (see
 * app/import-categories.php). Each term stores the original numeric category id
 * (from product_list/<catId>.html) as term meta `_sanyuan_cat_id`.
 *
 * Front end keeps the ORIGINAL design: a category archive
 * (/danh-muc-san-pham/<slug>/, WooCommerce's term base) is rendered from the
 * matching product_list/<catId>.html mirror, with its inner product/category
 * links rewritten to the managed WC URLs (via mirror_html). Categories without
 * a mirror file fall through to WooCommerce's default archive template.
 *
 * Why the WC term-archive URL (not /product_list/<id>.html): the front web
 * server serves .html as static files and never forwards arbitrary clean paths
 * to PHP — only WP-registered URLs (pages, WC products, WC term archives) reach
 * WordPress. Term archives are registered, so they render; see [[woo-product-detail]].
 */

namespace App;

const SANYUAN_CAT_META = '_sanyuan_cat_id';

/** Absolute path to a category's mirror file, or '' if absent. */
function sanyuan_category_mirror_file(string $catId): string
{
    $file = get_theme_file_path('public/site/product_list/' . $catId . '.html');
    return is_readable($file) ? $file : '';
}

/** Find the product_cat term carrying original category id $catId (or null). */
function sanyuan_term_by_catid(string $catId): ?\WP_Term
{
    $args = [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'number'     => 1,
        'meta_query' => [[ 'key' => SANYUAN_CAT_META, 'value' => $catId ]],
    ];
    // Đa ngôn ngữ: lấy term đúng ngôn ngữ hiện tại (tránh va chạm _sanyuan_cat_id).
    if (function_exists('App\\current_lang') && function_exists('pll_languages_list')) {
        $args['lang'] = current_lang();
    }
    $terms = get_terms($args);
    return (is_array($terms) && $terms && $terms[0] instanceof \WP_Term) ? $terms[0] : null;
}

/** original category id => term archive permalink, for every mirrored term. */
function sanyuan_cat_link_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    // Đa ngôn ngữ: cid => permalink cho term thuộc 1 ngôn ngữ ('' = mọi ngôn ngữ).
    $mapFor = function (string $slug): array {
        $args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'meta_query' => [[ 'key' => SANYUAN_CAT_META, 'compare' => 'EXISTS' ]],
        ];
        if ($slug !== '') {
            $args['lang'] = $slug;
        }
        $terms = get_terms($args);
        $out = [];
        if (is_array($terms)) {
            foreach ($terms as $t) {
                $cid  = get_term_meta($t->term_id, SANYUAN_CAT_META, true);
                $link = get_term_link($t);
                if ($cid && ! is_wp_error($link)) {
                    $out[(string) $cid] = $link;
                }
            }
        }
        return $out;
    };

    $poly = function_exists('App\\current_lang') && function_exists('pll_languages_list');
    if (! $poly) {
        $map = $mapFor('');
    } else {
        // Nền là ngôn ngữ MẶC ĐỊNH (đầy đủ), đè bằng bản dịch ngôn ngữ hiện tại
        // nếu có → link danh mục trên trang ngôn ngữ phụ không vỡ khi term chưa
        // được dịch (fallback về term mặc định).
        $lang = current_lang();
        $def  = default_lang();
        $map  = $mapFor($def);
        // An toàn: nếu chưa term nào gán ngôn ngữ mặc định thì lấy tất cả, tránh
        // vỡ toàn bộ link danh mục khi dữ liệu ngôn ngữ chưa đủ.
        if (! $map) {
            $map = $mapFor('');
        }
        if ($lang !== $def) {
            foreach ($mapFor($lang) as $cid => $link) {
                $map[$cid] = $link;
            }
        }
    }

    // Alternate ids the CMS assigned to the same category (the footer links
    // Instrumentation Cables via a different id than the menu). Alias them to
    // the canonical term so those links also reach WooCommerce.
    $aliases = ['1439926757560438784' => '616']; // Instrumentation Cables (footer id => menu id)
    foreach ($aliases as $from => $to) {
        if (! isset($map[$from]) && isset($map[$to])) {
            $map[$from] = $map[$to];
        }
    }

    return $map;
}

/**
 * Rewrite mirror links to the static category files
 * (href="(../|/)*product_list/<catId>.html") to the matching WC term archive.
 * Mirrored ids only; unknown ids keep their original link. Called from
 * mirror_html() alongside sanyuan_rewrite_product_links().
 */
function sanyuan_rewrite_category_links(string $html): string
{
    $map = sanyuan_cat_link_map();
    if (! $map) {
        return $html;
    }
    return preg_replace_callback(
        '~href="(?:\.\./|/)*product_list/([0-9]+)\.html"~i',
        function ($m) use ($map) {
            return isset($map[$m[1]]) ? 'href="' . esc_url($map[$m[1]]) . '"' : $m[0];
        },
        $html
    ) ?? $html;
}

/**
 * Rewrite BARE numeric category links (href="<catId>.html", no product_list/
 * prefix) to their WC term archives. On a category page the <base> is the
 * product_list/ directory, so the subcategory tiles + sidebar link to siblings
 * as href="81.html" — which resolves to the static theme file. ONLY call this
 * for category-page renders (where a bare <num>.html means a category).
 */
function sanyuan_rewrite_bare_category_links(string $html): string
{
    $map = sanyuan_cat_link_map();
    if (! $map) {
        return $html;
    }
    return preg_replace_callback(
        '~href="([0-9]+)\.html"~i',
        function ($m) use ($map) {
            return isset($map[$m[1]]) ? 'href="' . esc_url($map[$m[1]]) . '"' : $m[0];
        },
        $html
    ) ?? $html;
}

/*
|--------------------------------------------------------------------------
| Router — render the mirror product_list design for a WC category archive
|--------------------------------------------------------------------------
| Priority -1, before the generic .html mirror handler. Categories with no
| mirror file fall through to WooCommerce's default archive template.
*/
add_action('template_redirect', function () {
    if (! function_exists('is_product_category') || ! is_product_category()) {
        return;
    }
    $term = get_queried_object();
    if (! ($term instanceof \WP_Term)) {
        return;
    }
    $catId = (string) get_term_meta($term->term_id, SANYUAN_CAT_META, true);
    if ($catId === '' || sanyuan_category_mirror_file($catId) === '') {
        return; // not a mirrored category -> default WooCommerce archive
    }

    $html = mirror_html('product_list/' . $catId . '.html');
    if ($html === '') {
        return;
    }
    if (function_exists('get_field')) {
        $html = inject_header($html);
        $html = inject_footer($html);
        $html = inject_chrome($html);
    }
    $html = sanyuan_finalize_links($html);
    // Category page: <base> is product_list/, so bare href="<catId>.html" tiles
    // and sidebar links point to sibling categories — rewrite them too.
    $html = sanyuan_rewrite_bare_category_links($html);
    nocache_headers();
    echo $html;
    exit;
}, -1);
