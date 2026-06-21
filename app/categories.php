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

const SANYUAN_CAT_META        = '_sanyuan_cat_id';
const SANYUAN_CAT_RENDER      = 'v11';
const SANYUAN_CAT_TREE_VER_OPT = 'sanyuan_cat_tree_ver';

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

/** EN => ZH category labels from Polylang term translations (wp-admin source of truth). */
function sanyuan_category_polylang_name_pairs(): array
{
    static $pairs = null;
    if ($pairs !== null) {
        return $pairs;
    }

    $pairs = [];
    if (! function_exists('pll_get_term') || ! function_exists('default_lang')) {
        return $pairs;
    }

    global $wpdb;
    $def = default_lang();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT t.term_id, t.name
           FROM {$wpdb->terms} t
           JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat'
           JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id AND tm.meta_key = %s
           JOIN {$wpdb->term_relationships} tr ON tr.object_id = t.term_id
           JOIN {$wpdb->term_taxonomy} ttl ON ttl.term_taxonomy_id = tr.term_taxonomy_id AND ttl.taxonomy = 'language'
           JOIN {$wpdb->terms} tl ON tl.term_id = ttl.term_id AND tl.slug = %s",
        SANYUAN_CAT_META,
        $def
    ));

    foreach ($rows ?: [] as $row) {
        $enName = (string) $row->name;
        $zhId   = (int) (pll_get_term((int) $row->term_id, 'zh') ?: 0);
        if ($enName === '' || $zhId <= 0) {
            continue;
        }
        $zh = get_term($zhId, 'product_cat');
        if (! $zh || is_wp_error($zh)) {
            continue;
        }
        $zhName = (string) $zh->name;
        if ($zhName !== '' && $zhName !== $enName) {
            $pairs[$enName] = $zhName;
        }
    }
    uksort($pairs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    return $pairs;
}

/** EN => ZH labels parsed from mirror HTML — import/repair tooling only, not front-end. */
function sanyuan_category_mirror_name_pairs(): array
{
    static $pairs = null;
    if ($pairs !== null) {
        return $pairs;
    }

    $pairs = [];
    if (! function_exists(__NAMESPACE__ . '\\sanyuan_parse_category_tree')) {
        require_once get_theme_file_path('app/import-categories.php');
    }

    $en = sanyuan_parse_category_tree()['categories'];
    $zh = sanyuan_parse_category_tree_file('public/site/zh/product.html')['categories'];
    foreach ($en as $catId => $enC) {
        $enName = (string) ($enC['name'] ?? '');
        $zhName = (string) ($zh[$catId]['name'] ?? '');
        if ($enName !== '' && $zhName !== '' && $enName !== $zhName) {
            $pairs[$enName] = $zhName;
        }
    }
    uksort($pairs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    return $pairs;
}

/** Static UI strings on category mirror pages (non-category copy). */
function sanyuan_category_ui_strings(): array
{
    return [
        'Home page'                          => '首页',
        'Product Types'                      => '产品类型',
        'Highlight'                          => '推荐产品',
        'View all products'                  => '查看全部产品',
        'HangZhou SANYUAN Cable Co., Ltd'    => '杭州三元电缆有限公司',
        'Product Types-'                     => '产品类型-',
        '-Product Types-'                    => '-产品类型-',
        'Click to load more'                 => '点击加载更多',
        'No more'                            => '没有更多了',
        'No data'                            => '暂无数据',
        'Loading'                            => '加载中',
    ];
}

/** Bust category-page transients when a product_cat term changes in wp-admin. */
function sanyuan_bump_category_tree_version(): void
{
    update_option(SANYUAN_CAT_TREE_VER_OPT, (string) time(), false);
}

add_action('edited_product_cat', __NAMESPACE__ . '\\sanyuan_bump_category_tree_version');
add_action('created_product_cat', __NAMESPACE__ . '\\sanyuan_bump_category_tree_version');
add_action('delete_product_cat', __NAMESPACE__ . '\\sanyuan_bump_category_tree_version');

function sanyuan_category_tree_version(): string
{
    return (string) get_option(SANYUAN_CAT_TREE_VER_OPT, '0');
}

/** Root mirrored product_cat terms for the current Polylang language. */
function sanyuan_mirrored_root_categories(): array
{
    $args = [
        'taxonomy'   => 'product_cat',
        'parent'     => 0,
        'hide_empty' => false,
        'meta_query' => [[ 'key' => SANYUAN_CAT_META, 'compare' => 'EXISTS' ]],
        'orderby'    => 'name',
        'order'      => 'ASC',
    ];
    if (function_exists(__NAMESPACE__ . '\\current_lang') && function_exists('pll_languages_list')) {
        $args['lang'] = current_lang();
    }
    $terms = get_terms($args);

    return is_array($terms) ? $terms : [];
}

/** Published products assigned directly to one category (current language). */
function sanyuan_category_direct_products(int $termId): array
{
    if ($termId <= 0) {
        return [];
    }
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'tax_query'      => [[
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => [$termId],
            'include_children' => false,
        ]],
    ];
    if (function_exists(__NAMESPACE__ . '\\current_lang') && function_exists('pll_languages_list')) {
        $args['lang'] = current_lang();
    }

    return get_posts($args);
}

function sanyuan_render_category_tree_product(\WP_Post $post): string
{
    return '<li class="p_c_item p_loopitem"><p class="p_c_title3 deep ">'
        . '<a href="' . esc_url(get_permalink($post)) . '" target="_self">'
        . '<span>' . esc_html(get_the_title($post)) . '</span></a></p></li>';
}

function sanyuan_render_category_tree_term(\WP_Term $term, int $depth = 1): string
{
    $link = get_term_link($term);
    if (is_wp_error($link)) {
        return '';
    }
    $titleClass = 'p_c_title' . min(max($depth, 1), 2);
    $childArgs    = [
        'taxonomy'   => 'product_cat',
        'parent'     => $term->term_id,
        'hide_empty' => false,
        'meta_query' => [[ 'key' => SANYUAN_CAT_META, 'compare' => 'EXISTS' ]],
        'orderby'    => 'name',
        'order'      => 'ASC',
    ];
    if (function_exists(__NAMESPACE__ . '\\current_lang') && function_exists('pll_languages_list')) {
        $childArgs['lang'] = current_lang();
    }
    $children = get_terms($childArgs);
    $products = sanyuan_category_direct_products((int) $term->term_id);
    $inner    = '';
    if ((is_array($children) && $children) || $products) {
        $inner .= '<ul class="p_c_content deep-' . min($depth + 1, 3) . '">';
        if (is_array($children)) {
            foreach ($children as $child) {
                if ($child instanceof \WP_Term) {
                    $inner .= sanyuan_render_category_tree_term($child, $depth + 1);
                }
            }
        }
        foreach ($products as $post) {
            if ($post instanceof \WP_Post) {
                $inner .= sanyuan_render_category_tree_product($post);
            }
        }
        $inner .= '</ul>';
    }

    return '<li class="p_c_item p_loopitem"><p class="' . $titleClass . ' deep ">'
        . '<a href="' . esc_url($link) . '" target="_self">'
        . '<span>' . esc_html($term->name) . '</span></a></p>' . $inner . '</li>';
}

/** Rebuild the Product Types sidebar from WooCommerce + Polylang term names. */
function sanyuan_build_product_types_tree_html(): string
{
    $items = '';
    foreach (sanyuan_mirrored_root_categories() as $term) {
        if ($term instanceof \WP_Term) {
            $items .= sanyuan_render_category_tree_term($term, 1);
        }
    }

    return $items;
}

/** Swap the static mirror mega-menu for live product_cat data (Product Types panel). */
function sanyuan_inject_product_types_sidebar(string $html): string
{
    $tree = sanyuan_build_product_types_tree_html();
    if ($tree === '') {
        return $html;
    }

    $out = preg_replace(
        '~(<div class="e_categoryB-21[^"]*"[^>]*>\s*<div class="p_c_tree">\s*<ul class="p_c_content deep-1">)(.*?)(</ul>\s*</div>\s*</div>)~s',
        '$1' . $tree . '$3',
        $html,
        1
    );

    return is_string($out) ? $out : $html;
}

/** Normalize a highlight-tree href to a stable lookup key. */
function sanyuan_highlight_href_key(string $href): string
{
    $href = strtolower(trim(str_replace('\\', '/', $href)));
    if (preg_match('~product_details/([0-9]+)\.html~', $href, $m)) {
        return 'pid:' . $m[1];
    }
    if (preg_match('~product_list/([0-9]+)\.html~', $href, $m)) {
        return 'cat:' . $m[1];
    }
    if (preg_match('~(?:^|/)product/([0-9]+)\.html~', $href, $m)) {
        return 'page:' . $m[1];
    }
    if (preg_match('~([0-9]+)\.html$~', $href, $m)) {
        return 'cat:' . $m[1];
    }

    return 'href:' . md5($href);
}

/** Parse the Highlight sidebar tree (product_list e_categoryB-23 / product e_categoryB-18). */
function sanyuan_parse_highlight_tree_file(string $relPath): array
{
    static $cache = [];
    if (isset($cache[$relPath])) {
        return $cache[$relPath];
    }

    $file = get_theme_file_path($relPath);
    if (! is_readable($file)) {
        return $cache[$relPath] = [];
    }
    $html = file_get_contents($file);
    if (! is_string($html) || ! preg_match(
        '~<div class="e_categoryB-(?:23|18)[^"]*"[^>]*>\s*<div class="p_c_tree">\s*<ul class="p_c_content deep-1">(.*)</ul>\s*</div>\s*</div>~s',
        $html,
        $m
    )) {
        return $cache[$relPath] = [];
    }

    $dom = sanyuan_dom('<ul class="p_c_content deep-1">' . $m[1] . '</ul>');
    $xp  = new \DOMXPath($dom);
    $has = static fn (string $c): string => "contains(concat(' ', normalize-space(@class), ' '), ' {$c} ')";

    $walk = function (\DOMElement $ul, int $depth) use (&$walk, $xp, $has): array {
        $nodes = [];
        foreach ($xp->query('./li[' . $has('p_c_item') . ']', $ul) as $li) {
            if (! $li instanceof \DOMElement) {
                continue;
            }
            $a = $xp->query('./p[contains(@class, "p_c_title")]/a', $li)->item(0);
            if (! $a instanceof \DOMElement) {
                continue;
            }
            $node = [
                'href'     => (string) $a->getAttribute('href'),
                'name'     => trim($a->textContent),
                'depth'    => $depth,
                'children' => [],
            ];
            foreach ($xp->query('./ul[contains(@class, "p_c_content")]', $li) as $childUl) {
                if ($childUl instanceof \DOMElement) {
                    $node['children'] = $walk($childUl, min($depth + 1, 3));
                }
            }
            $nodes[] = $node;
        }

        return $nodes;
    };

    $root = $xp->query('//ul[' . $has('deep-1') . ']')->item(0);

    return $cache[$relPath] = ($root instanceof \DOMElement) ? $walk($root, 1) : [];
}

/** href-key => label from the ZH Highlight tree shell. */
function sanyuan_highlight_zh_name_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $walk = function (array $nodes) use (&$walk, &$map): void {
        foreach ($nodes as $node) {
            $key = sanyuan_highlight_href_key((string) ($node['href'] ?? ''));
            $name = (string) ($node['name'] ?? '');
            if ($key !== '' && $name !== '') {
                $map[$key] = $name;
            }
            if (! empty($node['children'])) {
                $walk($node['children']);
            }
        }
    };
    $walk(sanyuan_parse_highlight_tree_file('public/site/zh/product.html'));

    return $map;
}

function sanyuan_highlight_node_link(string $href): string
{
    $key = sanyuan_highlight_href_key($href);
    if (str_starts_with($key, 'pid:')) {
        $post = sanyuan_product_by_pid(substr($key, 4));
        if ($post instanceof \WP_Post) {
            $link = get_permalink($post);

            return is_string($link) ? $link : '#';
        }

        return '#';
    }
    if (str_starts_with($key, 'cat:')) {
        $map = sanyuan_cat_link_map();
        $cid = substr($key, 4);

        return isset($map[$cid]) ? (string) $map[$cid] : '#';
    }
    if (str_starts_with($key, 'page:')) {
        $url = function_exists(__NAMESPACE__ . '\\sanyuan_mirror_content_url')
            ? sanyuan_mirror_content_url('product/' . substr($key, 5))
            : '';

        return $url !== '' ? $url : '#';
    }

    return '#';
}

function sanyuan_highlight_node_label(array $node): string
{
    $href = (string) ($node['href'] ?? '');
    $key  = sanyuan_highlight_href_key($href);
    if (str_starts_with($key, 'pid:')) {
        $post = sanyuan_product_by_pid(substr($key, 4));
        if ($post instanceof \WP_Post) {
            return get_the_title($post);
        }
    }
    if (str_starts_with($key, 'cat:')) {
        $term = sanyuan_term_by_catid(substr($key, 4));
        if ($term instanceof \WP_Term && $term->name !== '') {
            return $term->name;
        }
    }
    if (function_exists(__NAMESPACE__ . '\\is_default_lang') && ! is_default_lang()) {
        $zh = sanyuan_highlight_zh_name_map()[$key] ?? '';
        if ($zh !== '') {
            return $zh;
        }
    }

    return (string) ($node['name'] ?? '');
}

function sanyuan_render_highlight_tree_nodes(array $nodes): string
{
    $html = '';
    foreach ($nodes as $node) {
        if (! is_array($node)) {
            continue;
        }
        $depth      = min(max((int) ($node['depth'] ?? 1), 1), 3);
        $titleClass = $depth <= 2 ? 'p_c_title' . $depth : 'p_c_title3';
        $link       = sanyuan_highlight_node_link((string) ($node['href'] ?? ''));
        $label      = sanyuan_highlight_node_label($node);
        $inner      = '';
        if (! empty($node['children']) && is_array($node['children'])) {
            $inner = '<ul class="p_c_content deep-' . min($depth + 1, 3) . '">'
                . sanyuan_render_highlight_tree_nodes($node['children']) . '</ul>';
        }
        $html .= '<li class="p_c_item p_loopitem"><p class="' . $titleClass . ' deep ">'
            . '<a href="' . esc_url($link) . '" target="_self">'
            . '<span>' . esc_html($label) . '</span></a></p>' . $inner . '</li>';
    }

    return $html;
}

function sanyuan_build_highlight_tree_html(): string
{
    return sanyuan_render_highlight_tree_nodes(
        sanyuan_parse_highlight_tree_file('public/site/product_list/26.html')
    );
}

/** Swap the static Highlight panel for mirror structure + live/WC labels. */
function sanyuan_inject_highlight_sidebar(string $html): string
{
    $tree = sanyuan_build_highlight_tree_html();
    if ($tree === '') {
        return $html;
    }

    $out = preg_replace(
        '~(<div class="e_categoryB-23[^"]*"[^>]*>\s*<div class="p_c_tree">\s*<ul class="p_c_content deep-1">)(.*?)(</ul>\s*</div>\s*</div>)~s',
        '$1' . $tree . '$3',
        $html,
        1
    );

    return is_string($out) ? $out : $html;
}

/** Deprecated: SEO meta comes from wp_head (Rank Math, etc.), not mirror HTML. */
function sanyuan_apply_category_term_meta(string $html, \WP_Term $term): string
{
    unset($term);

    return $html;
}

/** Swap EN mirror fragments using Polylang term names only (no mirror HTML fallback). */
function sanyuan_localize_category_html(string $html, \WP_Term $term): string
{
    if (function_exists(__NAMESPACE__ . '\\is_default_lang') && is_default_lang()) {
        return $html;
    }

    foreach (sanyuan_category_polylang_name_pairs() as $en => $zh) {
        $html = str_replace($en, $zh, $html);
    }
    foreach (sanyuan_category_ui_strings() as $en => $zh) {
        $html = str_replace($en, $zh, $html);
    }

    $html = preg_replace('/<html lang="en">/i', '<html lang="zh">', $html, 1) ?? $html;

    return $html;
}

/**
 * Render a mirrored WC category archive: EN product_list shell + shared chrome,
 * with ZH labels when the current language is not the default.
 */
function render_category(\WP_Term $term, string $catId): string
{
    $lang = function_exists(__NAMESPACE__ . '\\current_lang') ? current_lang() : 'en';
    $key  = 'sanyuan_cat_' . SANYUAN_CAT_RENDER . '_' . $lang . '_' . $term->term_id . '_'
        . sanyuan_category_tree_version() . '_'
        . md5((string) get_term_meta($term->term_id, SANYUAN_CAT_META, true));
    $cached = get_transient($key);
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $html = mirror_html('product_list/' . $catId . '.html');
    if ($html === '') {
        return '';
    }
    if (function_exists('get_field')) {
        $html = inject_header($html);
        $html = inject_footer($html);
        $html = inject_chrome($html);
    }
    $html = sanyuan_finalize_links($html);
    $html = sanyuan_rewrite_bare_category_links($html);
    $html = sanyuan_inject_product_types_sidebar($html);
    $html = sanyuan_inject_highlight_sidebar($html);
    $html = sanyuan_apply_category_term_meta($html, $term);
    $html = sanyuan_localize_category_html($html, $term);

    set_transient($key, $html, DAY_IN_SECONDS);

    return $html;
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

    $html = render_category($term, $catId);
    if ($html === '') {
        return;
    }
    nocache_headers();
    echo sanyuan_apply_wp_seo($html);
    exit;
}, -1);
