<?php

/**
 * One-off importer: turn the static News & Events cards in the mirror
 * (public/site/news.html, linking public/site/NewDetails/<id>.html) into real
 * `sy_news` posts.
 *
 * Idempotent — keyed on the `_sanyuan_news_src` meta (the original NewDetails
 * id) — so it can be re-run safely. NOT loaded at runtime; tools/run-import-news
 * .php bootstraps WordPress, requires this file and calls sanyuan_import_news().
 *
 * Each imported post links back to its rich original page via the `news_link`
 * ACF meta (→ /m/NewDetails/<id>/), and sideloads its card image as the post
 * thumbnail. Render is handled by App\sanyuan_inject_news() (app/news.php).
 */

namespace App;

/** /news/ listing section id (the "News & Event" list whose cards we import). */
const SANYUAN_NEWS_LIST_SECTION = 'c_static_001-17621364343180';

/** Live CMS (HTTrack mirror only bakes page 1; the rest come from here). */
const SANYUAN_NEWS_API_BASE = 'https://www.sanyuancable.com.cn';
const SANYUAN_NEWS_API_LIST = '/fwebapi/cms/lowcode/60003/18505/list?cate=0';
const SANYUAN_NEWS_IMAGE_CDN = 'https://omo-oss-image1.thefastimg.com/';
const SANYUAN_NEWS_IMAGE_VF  = 'B7gH3s';

/**
 * Parse EVERY card in the /news/ listing out of public/site/news.html — not just
 * the NewDetails ones, so the whole "News & Events" block becomes post-driven
 * (no card left as static HTML). Returns, in listing order:
 *   [['id','href','link','detail','img','title','excerpt','date'], …]
 *   - id     : stable source key for idempotent upsert (NewDetails numeric id,
 *              or the slug of the linked page e.g. 'best-fiber-optic-cable').
 *   - link   : public URL the card should point to (rich detail / landing page).
 *   - detail : mirror file (relative to the theme) holding the full article body.
 */
/** HTTP headers the news list API expects (from news.html _config). */
function sanyuan_news_api_headers(): array
{
    $es = 'list,page,DETAIL_ES.es_multi_image_51M01676,DETAIL_ES.es_symbol_text_2PVENH84,'
        . 'DETAIL_ES.es_symbol_text_FMK8817u,DETAIL_ES.es_date_prePublishTime,'
        . 'TEXT_DETAIL_ES.es_text_textarea_8B30N7U4';
    $fld = 'list,page,image_51M01676,text_2PVENH84,text_FMK8817u,prePublishTime,textarea_8B30N7U4';

    return [
        'Content-Type'       => 'application/json',
        'Data-Query-Es-Field' => $es,
        'Data-Query-Field'    => $fld,
    ];
}

/** One page of cards from the live CMS list API. */
function sanyuan_news_api_fetch_page(int $from, int $size): ?array
{
    if (! function_exists('wp_remote_post')) {
        return null;
    }
    $body = wp_json_encode([
        'size'   => $size,
        'from'   => $from,
        'query'  => [],
        'sort'   => [],
        'header' => [
            'Data-Query-Es-Field' => sanyuan_news_api_headers()['Data-Query-Es-Field'],
            'Data-Query-Random'   => 0,
            'Data-Query-Field'    => sanyuan_news_api_headers()['Data-Query-Field'],
        ],
    ]);
    $res = wp_remote_post(SANYUAN_NEWS_API_BASE . SANYUAN_NEWS_API_LIST, [
        'timeout' => 45,
        'headers' => sanyuan_news_api_headers(),
        'body'    => $body,
    ]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        return null;
    }
    $json = json_decode((string) wp_remote_retrieve_body($res), true);
    $data = $json['data'] ?? null;
    return is_array($data) && ($data['code'] ?? '') === '200' ? $data : null;
}

/** Map one CMS list row → the same card shape as sanyuan_extract_news_cards(). */
function sanyuan_news_card_from_api_row(array $row): array
{
    $href = (string) ($row['_href'] ?? $row['link_6W72508r'] ?? '');
    $href = preg_replace('~^[a-z]+://[^/]+~i', '', $href); // strip host if present
    $href = ltrim($href, '/');

    if (preg_match('~^NewDetails/(\d+)\.html~', $href, $m)) {
        $id     = $m[1];
        $link   = home_url('/m/NewDetails/' . $id . '/');
        $detail = 'public/site/NewDetails/' . $id . '.html';
    } else {
        $base   = basename(preg_replace('~[?#].*$~', '', $href));
        $id     = sanitize_title(preg_replace('~\.html$~i', '', $base));
        $link   = home_url('/' . $base);
        $detail = 'public/site/' . $base;
    }

    $img       = '';
    $imageCms  = '';
    $imgs      = $row['image_51M01676'] ?? [];
    if (is_array($imgs) && isset($imgs[0]['imageUrl'])) {
        $imageCms = (string) $imgs[0]['imageUrl'];
        $stem     = preg_replace('~\.[^.]+$~', '', basename($imageCms));
        $hits     = glob(get_theme_file_path('public/assets_img/' . $stem . '*')) ?: [];
        $img      = $hits ? '../assets_img/' . basename($hits[0]) : '';
    }

    $dateRaw = (string) ($row['prePublishTime'] ?? $row['preFirstPublishTime'] ?? '');
    $date    = $dateRaw !== '' ? substr($dateRaw, 0, 10) : '';

    return [
        'id'          => $id,
        'href'        => $href,
        'link'        => $link,
        'detail'      => $detail,
        'remote_href' => $href,
        'img'         => $img,
        'image_cms'   => $imageCms,
        'title'       => trim((string) ($row['text_2PVENH84'] ?? '')),
        'excerpt'     => trim((string) ($row['textarea_8B30N7U4'] ?? '')),
        'date'        => $date,
    ];
}

/** All listing cards from the live CMS (81 items); [] when API unreachable. */
function sanyuan_fetch_news_cards_from_api(): array
{
    $out  = [];
    $from = 0;
    $size = 20;

    while (true) {
        $page = sanyuan_news_api_fetch_page($from, $size);
        if ($page === null || empty($page['list'])) {
            break;
        }
        foreach ($page['list'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = sanyuan_news_card_from_api_row($row);
        }
        $total = (int) ($page['page']['totalCount'] ?? 0);
        $from += count($page['list']);
        if ($from >= $total || count($page['list']) < $size) {
            break;
        }
    }

    return $out;
}

/** Prefer live CMS list; fall back to the 5 cards baked into news.html. */
function sanyuan_collect_news_cards(): array
{
    $api = sanyuan_fetch_news_cards_from_api();
    return $api !== [] ? $api : sanyuan_extract_news_cards();
}

function sanyuan_extract_news_cards(): array
{
    $file = get_theme_file_path('public/site/news.html');
    if (! is_readable($file)) {
        return [];
    }
    $html = file_get_contents($file);

    // Scope to the listing's <div class="p_list"> … so we never pick up cards
    // from other sections (related blocks, footers, …).
    if (! preg_match(
        '~id="' . preg_quote(SANYUAN_NEWS_LIST_SECTION, '~')
            . '".*?<div class="p_list">(.*?)</div>\s*<div class="p_page">~s',
        $html, $sm
    )) {
        return [];
    }
    $list = $sm[1];

    $clean = fn (string $s): string => trim(html_entity_decode(
        wp_strip_all_tags($s), ENT_QUOTES, 'UTF-8'
    ));

    $out = [];
    if (preg_match_all(
        '~<a class="[^"]*\bp_loopitem\b[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)</a>~s',
        $list, $mm, PREG_SET_ORDER
    )) {
        foreach ($mm as $m) {
            $href = $m[1];
            $blk  = $m[2];

            // Resolve a stable id + public link + the mirror file with the body.
            if (preg_match('~NewDetails/(\d+)\.html~', $href, $h2)) {
                $id     = $h2[1];
                $link   = home_url('/m/NewDetails/' . $id . '/');
                $detail = 'public/site/NewDetails/' . $id . '.html';
            } else {
                $base   = basename(preg_replace('~[?#].*$~', '', $href)); // best-fiber-optic-cable.html
                $id     = sanitize_title(preg_replace('~\.html$~i', '', $base));
                $link   = home_url('/' . $base);
                $detail = 'public/site/' . $base;
            }

            $img = preg_match('~<img\s+src="([^"]+)"~', $blk, $x) ? $x[1] : '';
            $title = preg_match('~\be_text-4\b[^"]*">\s*(.*?)\s*</p>~s', $blk, $x) ? $clean($x[1]) : '';
            $excerpt = preg_match('~\be_richText-\d+\b[^"]*\bclearfix"[^>]*>\s*(.*?)\s*</div>~s', $blk, $x) ? $clean($x[1]) : '';
            $date = preg_match('~\be_timeFormat-\d+\b[^"]*">\s*(.*?)\s*</[pP]>~s', $blk, $x) ? $clean($x[1]) : '';

            $out[] = array_merge(compact('id', 'href', 'link', 'detail', 'img', 'title', 'excerpt', 'date'), [
                'remote_href' => $href,
                'image_cms'   => '',
            ]);
        }
    }
    return $out;
}

/**
 * Pull the full article body out of a card's mirror detail page: the largest
 * `e_richText-N` block (the original 300.cn body), rebuilt as clean WordPress
 * markup — paragraphs split on blank lines, **bold** → <strong>, single line
 * breaks preserved. Returns '' when no usable body is found (caller falls back
 * to the card excerpt), so a missing/odd page never produces an empty post.
 */
/** Pull article body HTML from a mirror detail page string. */
function sanyuan_news_body_from_html(string $html): string
{
    if (! preg_match_all('~e_richText-\d+\b[^>]*>(.*?)</div>~s', $html, $mm)) {
        return '';
    }
    // Pick the block with the most actual text (skip the © footer block, etc.).
    $best = '';
    $bestLen = 0;
    foreach ($mm[1] as $blk) {
        $len = strlen(trim(wp_strip_all_tags($blk)));
        if ($len > $bestLen) {
            $bestLen = $len;
            $best = $blk;
        }
    }
    if ($bestLen < 200) {
        return '';
    }

    $text = html_entity_decode(wp_strip_all_tags($best), ENT_QUOTES, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    $out = '';
    foreach (preg_split('~\n\s*\n~', trim($text)) as $para) {
        $para = trim($para);
        if ($para === '') {
            continue;
        }
        $para = esc_html($para);                                   // escape first…
        $para = preg_replace('~\*\*(.+?)\*\*~s', '<strong>$1</strong>', $para); // …then markup
        $out .= '<p>' . nl2br($para) . "</p>\n";
    }
    return $out;
}

function sanyuan_news_body_from_detail(string $detailRel): string
{
    $file = get_theme_file_path($detailRel);
    if (is_readable($file)) {
        return sanyuan_news_body_from_html((string) file_get_contents($file));
    }
    return '';
}

/** Fetch a NewDetails / landing page from the live site when no local mirror file. */
function sanyuan_news_body_from_remote(string $relHref): string
{
    if ($relHref === '' || ! function_exists('wp_remote_get')) {
        return '';
    }
    $url = SANYUAN_NEWS_API_BASE . '/' . ltrim($relHref, '/');
    $res = wp_remote_get($url, ['timeout' => 45]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        return '';
    }
    return sanyuan_news_body_from_html((string) wp_remote_retrieve_body($res));
}

/** Full CDN URL for a CMS `portal-saas/...` image path (requires ?vf= token). */
function sanyuan_news_cms_image_cdn_url(string $portalPath): string
{
    $portalPath = ltrim($portalPath, '/');
    return $portalPath === ''
        ? ''
        : SANYUAN_NEWS_IMAGE_CDN . $portalPath . '?vf=' . SANYUAN_NEWS_IMAGE_VF;
}

/** og:image from a live detail page — fallback when CMS path sideload fails. */
function sanyuan_news_og_image_url(string $relHref): string
{
    if ($relHref === '' || ! function_exists('wp_remote_get')) {
        return '';
    }
    $url = SANYUAN_NEWS_API_BASE . '/' . ltrim($relHref, '/');
    $res = wp_remote_get($url, ['timeout' => 30]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        return '';
    }
    if (! preg_match('~property="og:image"\s+content="([^"]+)"~i', (string) wp_remote_retrieve_body($res), $m)) {
        return '';
    }
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

/** Sideload a remote image; idempotent via `_sanyuan_src` on the attachment. */
function sanyuan_attach_remote_image(string $url, int $postId, string $title, string $srcKey = ''): int
{
    if ($url === '' || ! function_exists('wp_remote_get')) {
        return 0;
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $srcKey = $srcKey !== '' ? $srcKey : $url;
    $existing = get_posts([
        'post_type'   => 'attachment',
        'post_status' => 'inherit',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => '_sanyuan_src',
        'meta_value'  => $srcKey,
    ]);
    if ($existing) {
        return (int) $existing[0];
    }

    $res = wp_remote_get($url, ['timeout' => 45]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        return 0;
    }
    $body = (string) wp_remote_retrieve_body($res);
    if (strlen($body) < 500) {
        return 0;
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    $base = basename($path !== '' ? $path : 'image.jpg');
    if (! preg_match('~\.(jpe?g|png|gif|webp)$~i', $base)) {
        $base .= '.jpg';
    }

    $upload = wp_upload_bits($base, null, $body);
    if (! empty($upload['error']) || empty($upload['file'])) {
        return 0;
    }

    $type  = wp_check_filetype($upload['file']);
    $attId = wp_insert_attachment([
        'post_mime_type' => $type['type'] ?: 'image/jpeg',
        'post_title'     => $title !== '' ? $title : $base,
        'post_content'   => '',
        'post_status'    => 'inherit',
    ], $upload['file'], $postId);

    if (is_wp_error($attId) || ! $attId) {
        return 0;
    }

    wp_update_attachment_metadata($attId, wp_generate_attachment_metadata($attId, $upload['file']));
    update_post_meta($attId, '_sanyuan_src', $srcKey);

    return (int) $attId;
}

/** Set featured image for one card: local mirror → CMS CDN → og:image. */
function sanyuan_news_attach_card_image(array $c, int $postId): string
{
    require_once __DIR__ . '/import-products.php';
    $att = 0;

    if (! empty($c['img'])) {
        $src = get_theme_file_path('public/assets_img/' . basename($c['img']));
        $att = sanyuan_attach_local_image($src, $postId, $c['title']);
    }
    if (! $att && ! empty($c['image_cms'])) {
        $att = sanyuan_attach_remote_image(
            sanyuan_news_cms_image_cdn_url($c['image_cms']),
            $postId,
            $c['title'],
            $c['image_cms']
        );
    }
    if (! $att && ! empty($c['remote_href'])) {
        $og = sanyuan_news_og_image_url($c['remote_href']);
        if ($og !== '') {
            $att = sanyuan_attach_remote_image($og, $postId, $c['title'], 'og:' . $c['id']);
        }
    }

    if ($att) {
        set_post_thumbnail($postId, $att);
        return 'yes';
    }

    return (! empty($c['img']) || ! empty($c['image_cms']) || ! empty($c['remote_href']))
        ? 'failed' : 'none';
}

/**
 * Ensure the "News & Events" category exists (creating it in the default
 * language when missing) and return its term id. Polylang-aware.
 */
function sanyuan_news_ensure_category(): int
{
    $existing = sanyuan_news_category_id();
    if ($existing) {
        return $existing;
    }
    $res = wp_insert_term('News & Events', 'category', ['slug' => SANYUAN_NEWS_CAT]);
    if (is_wp_error($res)) {
        return 0;
    }
    $termId = (int) $res['term_id'];
    if (function_exists('pll_set_term_language')) {
        $lang = function_exists('pll_default_language') ? pll_default_language() : 'en';
        pll_set_term_language($termId, $lang ?: 'en');
    }
    return $termId;
}

/**
 * Import every News listing card. $log is an optional callable(string).
 * Returns a summary array.
 */
function sanyuan_import_news(?callable $log = null): array
{
    $log = $log ?: function ($l) {};

    // Reuse the product importer's local-image sideloader.
    require_once __DIR__ . '/import-products.php';

    $cards = sanyuan_collect_news_cards();
    $summary = ['total' => count($cards), 'created' => 0, 'updated' => 0, 'errors' => 0];

    $catId = sanyuan_news_ensure_category();
    $lang  = function_exists('pll_default_language') ? (pll_default_language() ?: 'en') : 'en';

    kses_remove_filters();

    foreach ($cards as $i => $c) {
        $n = $i + 1;
        if ($c['title'] === '') {
            $summary['errors']++;
            $log(sprintf('[%d/%d] %s  ERROR: no title parsed', $n, count($cards), $c['id']));
            continue;
        }

        // Full article body from the linked detail page → dynamic post content
        // (no longer the thin card excerpt). Falls back to the excerpt if the
        // page has no extractable body.
        $body = sanyuan_news_body_from_detail($c['detail']);
        if ($body === '' && ! empty($c['remote_href'])) {
            $body = sanyuan_news_body_from_remote((string) $c['remote_href']);
        }
        if ($body === '') {
            $body = $c['excerpt'] !== '' ? '<p>' . esc_html($c['excerpt']) . '</p>' : '';
        }

        $existing = sanyuan_find_news_by_src($c['id'], true);
        $postarr = [
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_title'   => $c['title'],
            'post_excerpt' => $c['excerpt'],
            'post_content' => $body,
        ];
        if ($catId) {
            $postarr['post_category'] = [$catId];
        }
        $ts = $c['date'] !== '' ? strtotime($c['date']) : false;
        if ($ts) {
            $postarr['post_date']     = date('Y-m-d H:i:s', $ts);
            $postarr['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
        }

        if ($existing) {
            $postarr['ID'] = $existing->ID;
            $id = wp_update_post($postarr, true);
            $action = 'updated';
        } else {
            $postarr['post_name'] = sanitize_title($c['title'] . '-' . $c['id']);
            $id = wp_insert_post($postarr, true);
            $action = 'created';
        }
        if (is_wp_error($id) || ! $id) {
            $summary['errors']++;
            $log(sprintf('[%d/%d] %s  ERROR: save failed', $n, count($cards), $c['id']));
            continue;
        }

        update_post_meta($id, SANYUAN_NEWS_SRC_META, $c['id']);
        // news_link is left empty on import: the card links to the WP post, which
        // renders through App\sanyuan_render_news_detail() (chromed detail page).
        // It stays as an OPTIONAL manual override (e.g. an external press link).

        // Keep it filed under News & Events, in the default language (Polylang).
        if ($catId) {
            wp_set_post_categories($id, [$catId], false);
        }
        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($id, $lang);
        }

        $imgFlag = sanyuan_news_attach_card_image($c, (int) $id);

        $summary[$action]++;
        $title = strlen($c['title']) > 48 ? substr($c['title'], 0, 47) . '…' : $c['title'];
        $log(sprintf('[%d/%d] %s  #%d %s (img:%s) — %s', $n, count($cards), $c['id'], $id, $action, $imgFlag, $title));
    }

    kses_init_filters();

    return $summary;
}

/** Back-fill featured images for news posts that have none (fast re-run). */
function sanyuan_repair_news_thumbnails(?callable $log = null): array
{
    $log = $log ?: function ($l) {};

    $cards = sanyuan_collect_news_cards();
    $bySrc = [];
    foreach ($cards as $c) {
        $bySrc[$c['id']] = $c;
    }

    $catId = sanyuan_news_category_id();
    if (! $catId) {
        return ['total' => 0, 'fixed' => 0, 'failed' => 0, 'skipped' => 0];
    }

    $posts = get_posts([
        'post_type'        => 'post',
        'post_status'      => 'publish',
        'numberposts'      => -1,
        'category__in'     => [$catId],
        'suppress_filters' => false,
    ]);

    $summary = ['total' => count($posts), 'fixed' => 0, 'failed' => 0, 'skipped' => 0];

    foreach ($posts as $post) {
        if (get_post_thumbnail_id($post->ID)) {
            $summary['skipped']++;
            continue;
        }
        $src  = (string) get_post_meta($post->ID, SANYUAN_NEWS_SRC_META, true);
        $card = $bySrc[$src] ?? null;
        if ($card === null) {
            $summary['failed']++;
            $log(sprintf('#%d  no card for src=%s', $post->ID, $src));
            continue;
        }
        $flag = sanyuan_news_attach_card_image($card, $post->ID);
        if ($flag === 'yes') {
            $summary['fixed']++;
            $log(sprintf('#%d  fixed (%s)', $post->ID, $src));
        } else {
            $summary['failed']++;
            $log(sprintf('#%d  failed (%s)', $post->ID, $src));
        }
    }

    return $summary;
}
