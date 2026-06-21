<?php

/**
 * WooCommerce product detail — SANYUAN design integration.
 *
 * The original 300.cn site renders every product at /product_Details/<id>.html
 * from a single shared template (same 3 stylesheets, same e_container-8 layout:
 * H1 title + main image + short subtitle + "Datasheet Download" + a rich
 * description with the spec tables). It is an INQUIRY catalog — no price, no
 * cart.
 *
 * We keep that exact design and that exact URL, but drive the dynamic parts from
 * a real WooCommerce product so the catalog is managed in wp-admin:
 *
 *   - URL: /product_Details/<id>.html stays canonical. A product carries the
 *     original numeric id as meta `_sanyuan_pid`; the router resolves the id to
 *     its WC product and renders the design. Un-imported ids fall back to the
 *     untouched static mirror (so nothing 404s mid-migration).
 *   - Render: load the product's own mirror shell via mirror_html() (identical
 *     head + CSS + header + footer + <base>), then overlay the 5 anchored
 *     regions with the WC product's data. Fresh-imported products look
 *     byte-identical to the original; editing the product in wp-admin updates
 *     the page. Same "inject, don't rebuild" philosophy as the mirror pages.
 *
 * Field map (design element -> source):
 *   h1.e_h1-29           <- post_title
 *   .e_image-20 img      <- featured image (falls back to original baked image)
 *   .e_richText-21       <- short description (post_excerpt)
 *   .e_richText-16       <- description (post_content: Application + spec tables)
 *   a.e_sceneBtnFile-19  <- datasheet (ACF: product_datasheet_url/_name)
 */

namespace App;

const SANYUAN_PID_META = '_sanyuan_pid';

// Bump when render_product()/mirror_html() logic changes, to bust render caches.
const SANYUAN_RENDER_VER = 'v13';

/** Absolute path to a product's original mirror file, or '' if absent. */
function sanyuan_product_mirror_file(string $pid): string
{
    $file = get_theme_file_path('public/site/product_Details/' . $pid . '.html');
    return is_readable($file) ? $file : '';
}

/** Find the published WC product carrying original id $pid (or null). */
function sanyuan_product_by_pid(string $pid): ?\WP_Post
{
    $args = [
        'post_type'   => 'product',
        'post_status' => 'publish',
        'numberposts' => 1,
        'meta_key'    => SANYUAN_PID_META,
        'meta_value'  => $pid,
        'fields'      => 'all',
    ];
    // Đa ngôn ngữ: lấy bản sản phẩm đúng ngôn ngữ hiện tại (Polylang hiểu 'lang').
    if (function_exists('App\\current_lang') && function_exists('pll_languages_list')) {
        $args['lang'] = current_lang();
    }
    $q = get_posts($args);
    return $q ? $q[0] : null;
}

/** original id => WC permalink, for every imported product (built once/request). */
function sanyuan_product_link_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    global $wpdb;

    // Đa ngôn ngữ: pid => post_id cho sản phẩm thuộc 1 ngôn ngữ ('' = mọi ngôn
    // ngữ). Polylang lưu ngôn ngữ của post là term trong taxonomy 'language'
    // (slug = mã ngôn ngữ), nên lọc bằng JOIN.
    $rowsFor = function (string $slug) use ($wpdb): array {
        if ($slug === '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.meta_value AS pid, pm.post_id AS id
                   FROM {$wpdb->postmeta} pm
                   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                  WHERE pm.meta_key = %s AND p.post_status = 'publish' AND p.post_type = 'product'",
                SANYUAN_PID_META
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.meta_value AS pid, pm.post_id AS id
                   FROM {$wpdb->postmeta} pm
                   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                   JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'language'
                   JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                  WHERE pm.meta_key = %s AND p.post_status = 'publish' AND p.post_type = 'product' AND t.slug = %s",
                SANYUAN_PID_META,
                $slug
            ));
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->pid] = (int) $r->id;
        }
        return $out;
    };

    $poly = function_exists('App\\current_lang') && function_exists('pll_languages_list');
    if (! $poly) {
        $ids = $rowsFor('');
    } else {
        // Nền là ngôn ngữ MẶC ĐỊNH (luôn đầy đủ), rồi ĐÈ bằng bản dịch ngôn ngữ
        // hiện tại nếu có → trang ngôn ngữ phụ không bao giờ vỡ link dù sản phẩm
        // chưa được dịch (fallback về bản mặc định).
        $lang = current_lang();
        $def  = default_lang();
        $ids  = $rowsFor($def);
        // An toàn: nếu chưa sản phẩm nào được gán ngôn ngữ mặc định (map rỗng) thì
        // lấy tất cả, tránh vỡ TOÀN BỘ link sản phẩm khi dữ liệu ngôn ngữ chưa đủ.
        if (! $ids) {
            $ids = $rowsFor('');
        }
        if ($lang !== $def) {
            foreach ($rowsFor($lang) as $pid => $id) {
                $ids[$pid] = $id;
            }
        }
    }

    $map = [];
    foreach ($ids as $pid => $id) {
        $map[$pid] = get_permalink($id);
    }
    return $map;
}

/** Resolve a product_cat term id to the current language (Polylang-safe). */
function sanyuan_lang_product_cat(int $catId): ?\WP_Term
{
    if ($catId <= 0) {
        return null;
    }
    if (function_exists('pll_get_term') && function_exists(__NAMESPACE__ . '\\current_lang') && ! is_default_lang()) {
        $tid = (int) (pll_get_term($catId, current_lang()) ?: 0);
        if ($tid > 0) {
            $term = get_term($tid, 'product_cat');
            if ($term instanceof \WP_Term && ! is_wp_error($term)) {
                return $term;
            }
        }
    }
    $term = get_term($catId, 'product_cat');

    return ($term instanceof \WP_Term && ! is_wp_error($term)) ? $term : null;
}

/**
 * Rewrite mirror links that point at the static product files
 * (href="(../|/)*product_Details/<id>.html") to the matching WooCommerce
 * product permalink (root-absolute, so it ignores the page's <base>). Imported
 * ids only; un-imported ids keep their original static link. Called from
 * mirror_html() so every WP-rendered mirror page (menu, product listing,
 * related items) reaches the managed products.
 */
function sanyuan_rewrite_product_links(string $html): string
{
    $map = sanyuan_product_link_map();
    if (! $map) {
        return $html;
    }

    // (1) Static <a href> links in the server-rendered markup.
    $html = preg_replace_callback(
        '~href="(?:\.\./|/)*product_Details/([^"/]+)\.html"~i',
        function ($m) use ($map) {
            return isset($m[1], $map[$m[1]]) ? 'href="' . esc_url($map[$m[1]]) . '"' : $m[0];
        },
        $html
    ) ?? $html;

    // (2) Entity-encoded JSON datasource values (p_navProduct etc.). The 300.cn
    // runtime renders product grids / menus / search CLIENT-SIDE from this JSON,
    // building links from the raw &quot;…/product_Details/<id>.html&quot; paths —
    // which would otherwise resolve via <base> to the static theme files.
    //
    // Uses strtr (single pass, no PCRE backtrack/JIT limits): the datasource is
    // one ~784KB line, and a preg_replace_callback over it silently fails
    // (returns null) on php-fpm's lower PCRE JIT stack limit.
    //
    // Two quote forms: the entity form (&quot;) survives on string-rendered
    // pages (listing/category); on product detail pages render_product's
    // DOMDocument round-trip decodes the hidden-input value, leaving literal
    // double quotes ("…"). Both keys are safe — the replacement URL is clean.
    $pairs = [];
    foreach ($map as $pid => $url) {
        $pairs['&quot;/product_Details/' . $pid . '.html&quot;'] = '&quot;' . $url . '&quot;';
        $pairs['"/product_Details/' . $pid . '.html"'] = '"' . $url . '"';
    }
    if ($pairs) {
        $html = strtr($html, $pairs);
    }

    return $html;
}

/**
 * Drive the home page "Featured Product" grid (the e_loop-3 loop) from the
 * "Danh mục nổi bật" repeater on the front page (ACF `home_featured`: rows of
 * category + optional image + optional label) — matching the ORIGINAL design,
 * which is a curated set of CATEGORY tiles (not individual products). Same card
 * markup (cbox-3 p_loopitem / e_image-4 / e_text-5) so the original CSS +
 * carousel keep working. Empty repeater ⇒ empty grid (no mirror card fallback).
 */
function sanyuan_inject_featured(string $html): string
{
    if (! function_exists('get_field')) {
        return $html;
    }
    $front = (int) get_option('page_on_front');
    if ($front && function_exists('App\\lang_page_id')) {
        $front = lang_page_id($front); // multilingual: the translated front page
    }
    $rows = $front ? get_field('home_featured', $front) : null;

    $cards = '';
    if (is_array($rows)) {
        foreach ($rows as $r) {
        $catId = (int) ($r['cat'] ?? 0);
        if (! $catId) {
            continue;
        }
        $term = sanyuan_lang_product_cat($catId);
        if (! $term) {
            continue;
        }
        $link = get_term_link($term);
        if (is_wp_error($link)) {
            continue;
        }
        $label = ! empty($r['label']) ? (string) $r['label'] : $term->name;
        $img   = ! empty($r['image']) ? (string) $r['image'] : '';
        if ($img === '') {
            $tid = (int) get_term_meta($catId, 'thumbnail_id', true); // WC category image
            if ($tid) {
                $img = (string) wp_get_attachment_image_url($tid, 'medium');
            }
        }
        $cards .= '<div class="cbox-3 p_loopitem"><div class="e_image-4 s_img">'
            . '<a href="' . esc_url($link) . '" target="_self">'
            . '<img src="' . esc_url($img) . '" alt="' . esc_attr($label) . '" title="' . esc_attr($label) . '"/>'
            . '</a></div><p class="e_text-5 s_title">'
            . '<a href="' . esc_url($link) . '" target="_self">' . esc_html($label) . '</a>'
            . '</p></div>';
        }
    }

    return preg_replace_callback(
        '~(elem-id="e_loop-3".*?<div class="p_list">)(.*?)(</div>\s*<div class="p_page">)~s',
        function ($m) use ($cards) {
            return $m[1] . $cards . $m[3];
        },
        $html,
        1
    ) ?? $html;
}

/**
 * Rewrite BARE numeric product links (href="<pid>.html", no product_Details/
 * prefix) to their WC permalinks. On a product detail page the <base> is the
 * product_Details/ directory, so sibling/related/nav links appear as
 * href="5.html" — which resolves to the static theme file. ONLY call this for
 * product-page renders (where a bare <num>.html means a product).
 */
function sanyuan_rewrite_bare_product_links(string $html): string
{
    $map = sanyuan_product_link_map();
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
| Extraction — parse a product's original mirror fragments
|--------------------------------------------------------------------------
| Pure: file path in, associative array out. Used by the importer to seed a
| WC product, and reused nowhere at render time (render reads WC). Anchors are
| the unique e_* classes confirmed present exactly once in every product file.
*/

/** Load a (possibly huge) HTML string into a DOMDocument, UTF-8 safe, quiet. */
function sanyuan_dom(string $html): \DOMDocument
{
    $dom = new \DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // libxml defaults to ISO-8859-1; the xml prefix keeps UTF-8 category/product
    // names intact when parsing zh/product.html and other non-ASCII mirrors.
    $dom->loadHTML(
        '<?xml encoding="utf-8"?>' . $html,
        LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $dom;
}

/** XPath "has class X" predicate body. */
function sanyuan_has_class(string $class): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";
}

/** Inner HTML of a DOM element (children serialized). */
function sanyuan_inner_html(\DOMElement $el): string
{
    $out = '';
    foreach ($el->childNodes as $child) {
        $out .= $el->ownerDocument->saveHTML($child);
    }
    return trim($out);
}

/**
 * Extract the design fields from a product's original mirror file.
 * Returns null if the file is missing. Image is returned BOTH as the original
 * relative src and as a resolved absolute local path (under public/assets_img)
 * when that file exists, so the importer can sideload it.
 */
function sanyuan_extract_product(string $pid): ?array
{
    $file = sanyuan_product_mirror_file($pid);
    if ($file === '') {
        return null;
    }

    $dom = sanyuan_dom(file_get_contents($file));
    $xp  = new \DOMXPath($dom);

    $first = function (string $expr) use ($xp): ?\DOMElement {
        $n = $xp->query($expr);
        return ($n && $n->length) ? $n->item(0) : null;
    };

    $titleEl = $first('//h1[' . sanyuan_has_class('e_h1-29') . ']');
    $subEl   = $first('//*[' . sanyuan_has_class('e_richText-21') . ']');
    $descEl  = $first('//*[' . sanyuan_has_class('e_richText-16') . ']');
    $imgEl   = $first('//*[' . sanyuan_has_class('e_image-20') . ']//img');

    // Datasheet: hidden <input name="fileList" value="[{json}]"> in the button.
    $datasheet = null;
    $fileEl = $first('//input[@name="fileList"]');
    if ($fileEl) {
        $json = html_entity_decode((string) $fileEl->getAttribute('value'), ENT_QUOTES, 'UTF-8');
        $list = json_decode($json, true);
        if (is_array($list) && isset($list[0]) && is_array($list[0])) {
            $datasheet = [
                'url'  => (string) ($list[0]['fileUrl'] ?? $list[0]['filePath'] ?? ''),
                'name' => (string) ($list[0]['fileName'] ?? $list[0]['name'] ?? ''),
            ];
            if ($datasheet['url'] === '') {
                $datasheet = null;
            }
        }
    }

    // Resolve the product image to a local path under public/assets_img.
    $imgSrc = $imgEl ? (string) $imgEl->getAttribute('src') : '';
    $imgLocal = '';
    if ($imgSrc !== '' && preg_match('~assets_img/([^/?"]+)~', $imgSrc, $m)) {
        $cand = get_theme_file_path('public/assets_img/' . $m[1]);
        if (is_readable($cand)) {
            $imgLocal = $cand;
        }
    }

    // Page <meta> for SEO seed.
    $metaDesc = $first('//meta[@name="description"]');

    return [
        'pid'         => $pid,
        'title'       => $titleEl ? trim($titleEl->textContent) : '',
        'subtitle'    => $subEl ? sanyuan_inner_html($subEl) : '',
        'description' => $descEl ? sanyuan_inner_html($descEl) : '',
        'image_src'   => $imgSrc,
        'image_local' => $imgLocal,
        'datasheet'   => $datasheet,
        'meta_desc'   => $metaDesc ? (string) $metaDesc->getAttribute('content') : '',
    ];
}

/*
|--------------------------------------------------------------------------
| Render — overlay WC product data onto the original mirror shell
|--------------------------------------------------------------------------
*/

/** Replace an element's children with parsed HTML (UTF-8 safe). */
function sanyuan_set_inner_html(\DOMElement $el, string $html): void
{
    $doc = $el->ownerDocument;
    while ($el->firstChild) {
        $el->removeChild($el->firstChild);
    }
    if (trim($html) === '') {
        return;
    }
    $tmp = new \DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $tmp->loadHTML(
        '<?xml encoding="utf-8"?><div id="__wrap__">' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $wrap = $tmp->getElementById('__wrap__');
    if (! $wrap) {
        return;
    }
    foreach (iterator_to_array($wrap->childNodes) as $child) {
        $el->appendChild($doc->importNode($child, true));
    }
}

/**
 * Build the WC-driven field set for rendering. Featured image falls back to the
 * original baked image (kept in the shell) when no WC image is set.
 */
function sanyuan_product_render_fields(\WP_Post $post): array
{
    $pid = (string) get_post_meta($post->ID, SANYUAN_PID_META, true);

    $imgUrl = get_the_post_thumbnail_url($post->ID, 'large') ?: '';

    // Datasheet: prefer an uploaded file (ACF file field), else an external URL
    // (e.g. the original CDN datasheets seeded by the importer).
    $datasheet = null;
    if (function_exists('get_field')) {
        $file = get_field('product_datasheet_file', $post->ID); // return_format = array
        $dsName = (string) get_field('product_datasheet_name', $post->ID);
        if (is_array($file) && ! empty($file['url'])) {
            $datasheet = [
                'url'  => $file['url'],
                'name' => $dsName !== '' ? $dsName : (string) ($file['filename'] ?? basename($file['url'])),
            ];
        } else {
            $dsUrl = (string) get_field('product_datasheet_url', $post->ID);
            if ($dsUrl !== '') {
                $datasheet = ['url' => $dsUrl, 'name' => $dsName !== '' ? $dsName : basename($dsUrl)];
            }
        }
    }

    return [
        'pid'         => $pid,
        'title'       => $post->post_title,
        'subtitle'    => $post->post_excerpt,
        'description' => $post->post_content,
        'image_url'   => $imgUrl,
        'datasheet'   => $datasheet,
    ];
}

/**
 * Render the managed product page: the product's own mirror shell with the 5
 * anchored regions overlaid from WC, then the shared header/footer ACF chrome.
 * Cached in a transient keyed by the post's modified time so the DOM round-trip
 * runs once per edit, not once per request.
 */
function render_product(\WP_Post $post, string $pid): string
{
    // Đa ngôn ngữ: thêm ngôn ngữ vào key để bản en/zh cache tách biệt (mirror
    // shell + chrome khác nhau theo ngôn ngữ).
    $lang = function_exists('App\\current_lang') ? current_lang() : 'en';
    $cacheKey = 'sanyuan_prod_' . SANYUAN_RENDER_VER . '_' . $lang . '_' . $post->ID . '_' . $post->post_modified_gmt;
    $cached = get_transient($cacheKey);
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    // Start from the product's own original mirror (correct chrome + CSS + base).
    $html = mirror_html('product_Details/' . $pid . '.html');
    if ($html === '') {
        return '';
    }

    $f = sanyuan_product_render_fields($post);

    $dom = sanyuan_dom($html);
    $xp  = new \DOMXPath($dom);
    $first = function (string $expr) use ($xp): ?\DOMElement {
        $n = $xp->query($expr);
        return ($n && $n->length) ? $n->item(0) : null;
    };

    if (($el = $first('//h1[' . sanyuan_has_class('e_h1-29') . ']')) && $f['title'] !== '') {
        $el->textContent = $f['title'];
    }
    if (($el = $first('//*[' . sanyuan_has_class('e_image-20') . ']//img')) && $f['image_url'] !== '') {
        $el->setAttribute('src', $f['image_url']);
        if ($el->hasAttribute('lazy')) {
            $el->setAttribute('lazy', $f['image_url']);
        }
        $el->removeAttribute('needthumb');
    }
    if (($el = $first('//*[' . sanyuan_has_class('e_richText-21') . ']')) && $f['subtitle'] !== '') {
        sanyuan_set_inner_html($el, $f['subtitle']);
    }
    if (($el = $first('//*[' . sanyuan_has_class('e_richText-16') . ']')) && trim($f['description']) !== '') {
        // Inject raw: the stored description already carries the original
        // <p>/<table> structure, so wpautop would only add stray paragraphs.
        sanyuan_set_inner_html($el, $f['description']);
    }

    // Datasheet: rebuild the hidden fileList JSON, or hide the button if none.
    if ($el = $first('//input[@name="fileList"]')) {
        if ($f['datasheet']) {
            $payload = [[
                'fileName' => $f['datasheet']['name'],
                'fileUrl'  => $f['datasheet']['url'],
                'filePath' => $f['datasheet']['url'],
                'name'     => $f['datasheet']['name'],
                'title'    => $f['datasheet']['name'],
                'extName'  => strtolower(pathinfo($f['datasheet']['name'], PATHINFO_EXTENSION) ?: 'pdf'),
            ]];
            $el->setAttribute('value', wp_json_encode($payload));
        } elseif ($btn = $first('//a[' . sanyuan_has_class('e_sceneBtnFile-19') . ']')) {
            $btn->setAttribute('style', trim($btn->getAttribute('style') . ';display:none'));
        }
    }

    $out = $dom->saveHTML();

    // Shared header (logo/menu/search) + footer + contact-bar ACF chrome,
    // exactly like the mirror managed pages.
    if (function_exists('get_field')) {
        $out = inject_header($out);
        $out = inject_footer($out);
        $out = inject_chrome($out);
    }

    // Repoint product/category links LAST (after injection may re-insert originals).
    $out = sanyuan_finalize_links($out);
    // Product page: <base> is product_Details/, so bare href="<pid>.html"
    // sibling/related/nav links resolve to static theme files — rewrite them too.
    $out = sanyuan_rewrite_bare_product_links($out);

    set_transient($cacheKey, $out, DAY_IN_SECONDS);
    return $out;
}

// NOTE: the render transient is keyed on post_modified_gmt, so editing a
// product moves the key and the next request recomputes — old entries simply
// expire unread. No explicit purge hook needed.

/** Emit a product's managed design and stop, or do nothing if it can't render. */
function sanyuan_serve_product(\WP_Post $post): void
{
    $pid = (string) get_post_meta($post->ID, SANYUAN_PID_META, true);
    if ($pid === '' || sanyuan_product_mirror_file($pid) === '') {
        return; // no mirror shell -> let WooCommerce/theme handle it normally
    }
    $html = render_product($post, $pid);
    if ($html === '') {
        return;
    }
    nocache_headers();
    echo sanyuan_apply_wp_seo($html);
    exit;
}

/*
|--------------------------------------------------------------------------
| Router — render the SANYUAN design for a single WooCommerce product
|--------------------------------------------------------------------------
| Two entry points, both at template_redirect priority -1 (before the generic
| .html mirror handler in routes.php):
|
|  1. is_singular('product') — WooCommerce's native single-product URL
|     (permalink base set to /product_Details/, so /product_Details/<slug>/).
|     This is the working route: clean URLs reach WordPress on this host.
|
|  2. /product_Details/<id>.html — the ORIGINAL id-based URL. Only reachable if
|     the front web server forwards .html to PHP (it currently 404s them as
|     static files). Harmless if never hit; ready if an nginx rule is added.
|
| An un-imported id falls through so the static mirror still serves.
*/
add_action('template_redirect', function () {
    if (function_exists('is_singular') && is_singular('product')) {
        $post = get_queried_object();
        if ($post instanceof \WP_Post) {
            sanyuan_serve_product($post);
        }
        return;
    }

    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if (! preg_match('~^product_Details/([^/]+)\.html$~i', $path, $m)) {
        return;
    }
    if (sanyuan_product_mirror_file($m[1]) === '') {
        return; // unknown id -> WordPress 404
    }
    $post = function_exists('wc_get_product') ? sanyuan_product_by_pid($m[1]) : null;
    if ($post) {
        sanyuan_serve_product($post);
    }
}, -1);

/*
|--------------------------------------------------------------------------
| ACF — inquiry fields on the product editor (Vietnamese labels, matching
| the existing app/acf-pages.php style).
|--------------------------------------------------------------------------
*/
add_action('acf/init', function () {
    if (! function_exists('acf_add_local_field_group')) {
        return;
    }
    // NOTE: key must NOT be 'group_sanyuan_product' — that collides with the
    // per-page content group for the 'product' WP page (app/acf-pages.php uses
    // group_sanyuan_<slug>), which would hide these fields on the product editor.
    acf_add_local_field_group([
        'key'   => 'group_sanyuan_wc_product',
        'title' => 'Product — Datasheet',
        'fields' => [
            ['key' => 'field_sanyuan_ds_file', 'name' => 'product_datasheet_file',
             'label' => 'Datasheet file upload', 'type' => 'file',
             'return_format' => 'array', 'library' => 'all', 'mime_types' => 'pdf,doc,docx,xls,xlsx',
             'instructions' => 'Upload a PDF datasheet (or use an external URL below).'],
            ['key' => 'field_sanyuan_ds_url', 'name' => 'product_datasheet_url',
             'label' => 'Or external file URL', 'type' => 'url',
             'instructions' => 'Use only when no file is uploaded above. Ignored when a file is set.'],
            ['key' => 'field_sanyuan_ds_name', 'name' => 'product_datasheet_name',
             'label' => 'Datasheet display name', 'type' => 'text'],
        ],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
        'menu_order' => 0, 'style' => 'default',
    ]);

});

/**
 * The "featured categories" repeater field definition. NOT registered as its own
 * group — app/acf-pages.php splices it INTO the home page's "Featured Product"
 * tab so everything for that section lives in one place. Empty = keep the
 * original 6 cards (see sanyuan_inject_featured()).
 */
function sanyuan_home_featured_field(): array
{
    return [
        'key' => 'field_home_featured', 'name' => 'home_featured',
        'label' => 'Featured category slots', 'type' => 'repeater',
        'layout' => 'block', 'button_label' => 'Add category',
        'instructions' => 'Each row = one slot in the Featured Product grid.',
        'sub_fields' => [
            ['key' => 'field_hf_cat', 'name' => 'cat', 'label' => 'Category',
             'type' => 'taxonomy', 'taxonomy' => 'product_cat', 'field_type' => 'select',
             'return_format' => 'id', 'add_term' => 0, 'save_terms' => 0, 'load_terms' => 0,
             'allow_null' => 0, 'wrapper' => ['width' => '40']],
            ['key' => 'field_hf_img', 'name' => 'image', 'label' => 'Image (optional)',
             'type' => 'image', 'return_format' => 'url', 'preview_size' => 'thumbnail',
             'wrapper' => ['width' => '30']],
            ['key' => 'field_hf_label', 'name' => 'label', 'label' => 'Label (optional)',
             'type' => 'text', 'wrapper' => ['width' => '30']],
        ],
    ];
}
