<?php

/**
 * News & Events — driven by the standard WordPress blog (the `post` type) filed
 * under the "News & Events" category. Articles are edited in wp-admin → Bài viết
 * like any normal post; at render time we swap ONLY the cards inside each list's
 * e_loop-2 with the latest posts of that category — cloning the page's own card
 * markup so the original 300.cn CSS / animations keep working. No posts ⇒ the
 * baked cards are left exactly as they were, same fall-back philosophy as
 * App\sanyuan_inject_featured().
 *
 * (Earlier this used a dedicated `sy_news` custom post type; it was folded into
 * the default blog so editors manage everything from one place. See
 * app/migrate-news-to-blog.php for the one-off conversion.)
 *
 * Card image: the post's Featured image (overridable via the `news_image` field).
 * Card link: the post permalink, overridable via `news_link` — imported articles
 * point back to their rich original page (/m/NewDetails/<id>/, app/mirror-extra.php).
 *
 * Polylang: `post` is language-filtered, so the category + its posts carry a
 * language; the listing shows the posts of the page's current language.
 */

namespace App;

/** Category slug the "News & Events" blog posts live under. */
const SANYUAN_NEWS_CAT = 'news-events';

/** Post meta: stable source key from import (NewDetails id or landing slug). */
const SANYUAN_NEWS_SRC_META = '_sanyuan_news_src';

/** Find a news post by its mirror source id (idempotent import key). */
function sanyuan_find_news_by_src(string $srcId, bool $anyStatus = false): ?\WP_Post
{
    if ($srcId === '' || ! function_exists('get_posts')) {
        return null;
    }
    $q = get_posts([
        'post_type'        => 'post',
        'post_status'      => $anyStatus ? 'any' : 'publish',
        'numberposts'      => 1,
        'meta_key'         => SANYUAN_NEWS_SRC_META,
        'meta_value'       => $srcId,
        'suppress_filters' => $anyStatus, // idempotent import matches across languages
    ]);
    return $q ? $q[0] : null;
}

/**
 * Map a /m/ mirror relpath (e.g. NewDetails/123 or best-fiber-optic-cable) to the
 * `_sanyuan_news_src` value stored on imported posts.
 */
function sanyuan_news_src_from_mirror_path(string $path): string
{
    $path = trim($path, '/');
    if (preg_match('~^NewDetails/(\d+)$~', $path, $m)) {
        return $m[1];
    }
    return basename($path);
}

/** English News & Events category id (lookup under default language). */
function sanyuan_news_en_category_id(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    if (! function_exists('get_term_by')) {
        return $id = 0;
    }
    $prev = null;
    if (function_exists('PLL') && function_exists('default_lang')) {
        $prev        = PLL()->curlang;
        PLL()->curlang = PLL()->model->get_language(default_lang());
    }
    $t = get_term_by('slug', SANYUAN_NEWS_CAT, 'category');
    if ($prev !== null) {
        PLL()->curlang = $prev;
    }

    return $id = ($t ? (int) $t->term_id : 0);
}

/** Term id of the News & Events category in the CURRENT language (0 if absent). */
function sanyuan_news_category_id(): int
{
    $enId = sanyuan_news_en_category_id();
    if ($enId <= 0) {
        return 0;
    }
    if (function_exists('pll_get_term') && ! is_default_lang()) {
        $tid = (int) (pll_get_term($enId, current_lang()) ?: 0);
        if ($tid > 0) {
            return $tid;
        }
    }

    return $enId;
}

/** Fetch news posts for a category; optional EN fallback when the translation is empty. */
function sanyuan_news_fetch_posts(int $cat, array $args): array
{
    if ($cat <= 0 || ! function_exists('get_posts')) {
        return [];
    }
    $base = array_merge([
        'post_type'        => 'post',
        'post_status'      => 'publish',
        'category__in'     => [$cat],
        'suppress_filters' => false,
    ], $args);
    $posts = get_posts($base);
    if ($posts) {
        return $posts;
    }
    $enCat = sanyuan_news_en_category_id();
    if ($enCat <= 0 || $enCat === $cat) {
        return [];
    }

    return get_posts(array_merge($args, [
        'post_type'        => 'post',
        'post_status'      => 'publish',
        'category__in'     => [$enCat],
        'suppress_filters' => true,
        'lang'             => function_exists('default_lang') ? default_lang() : '',
    ]));
}

/** Cards per page on /news/ (matches mirror pageParamsJson size:5). */
const SANYUAN_NEWS_PER_PAGE = 5;

/** Home + /news/ list anchors: [WP page slug => [section id, posts per page]]. */
function sanyuan_news_targets(): array
{
    return [
        // Home "News & Events" block — original feed showed 4.
        'home' => ['c_static_001-17604262155250', 4],
        // /news/ listing — paginated blog posts.
        'news' => ['c_static_001-17621364343180', SANYUAN_NEWS_PER_PAGE],
    ];
}

/** Section id on /news/ where blog posts replace the mirror card list. */
const SANYUAN_NEWS_LIST_SECTION = 'c_static_001-17621364343180';

/** All ACF field names in the blog-driven list section (not edited on the News page). */
function sanyuan_news_list_section_field_names(): array
{
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $names = [];
    foreach (page_fields_data('news') as $f) {
        if (($f['section'] ?? '') === SANYUAN_NEWS_LIST_SECTION && ($f['key'] ?? '') !== '') {
            $names[] = $f['key'];
        }
    }

    return $names;
}

/** @deprecated Use sanyuan_news_list_section_field_names(). */
function sanyuan_news_page_fallback_field_names(): array
{
    return array_values(array_filter(
        sanyuan_news_list_section_field_names(),
        static fn (string $name): bool => $name !== 'news_text_3'
    ));
}

/** Remove Section 2 from wp-admin; article grid is driven by blog posts. */
add_filter('acf/load_fields', function (array $fields, array $parent): array {
    if (($parent['key'] ?? '') !== 'group_sanyuan_news') {
        return $fields;
    }

    $dropKeys = array_flip([
        'field_tab_' . SANYUAN_NEWS_LIST_SECTION,
        'field_show_' . SANYUAN_NEWS_LIST_SECTION,
        ...array_map(static fn (string $name) => 'field_' . $name, sanyuan_news_list_section_field_names()),
    ]);

    return array_values(array_filter($fields, static function ($f) use ($dropKeys) {
        return ! isset($dropKeys[$f['key'] ?? '']);
    }));
}, 10, 2);

/* ACF: card image override + optional detail link, shown on posts in the
   News & Events category (so normal blog posts stay clean). */
add_action('acf/init', function () {
    if (! function_exists('acf_add_local_field_group')) {
        return;
    }
    // Scope to the News category when it exists; otherwise fall back to all
    // posts so the fields are still reachable on a fresh install.
    $cat = sanyuan_news_category_id();
    $location = $cat
        ? [[
            ['param' => 'post_type', 'operator' => '==', 'value' => 'post'],
            ['param' => 'post_category', 'operator' => '==', 'value' => (string) $cat],
        ]]
        : [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]];

    acf_add_local_field_group([
        'key'   => 'group_sanyuan_news_post',
        'title' => 'News card (News & Events list)',
        'fields' => [
            ['key' => 'field_news_image', 'name' => 'news_image', 'label' => 'Card image',
             'type' => 'image', 'return_format' => 'url', 'preview_size' => 'medium'],
            ['key' => 'field_news_link', 'name' => 'news_link', 'label' => 'Detail link (optional)',
             'type' => 'url'],
        ],
        'location' => $location,
        'menu_order' => 0, 'style' => 'default', 'position' => 'side',
    ]);
});

/** Latest published news posts. Polylang-aware (current language). */
function sanyuan_news_query(int $limit): array
{
    $cat = sanyuan_news_category_id();
    if (! $cat) {
        return [];
    }

    return sanyuan_news_fetch_posts($cat, [
        'numberposts' => $limit,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);
}

/** Paginated news query for /news/ listing. */
function sanyuan_news_query_paged(int $page, int $perPage): array
{
    $empty = ['posts' => [], 'total' => 0, 'max_pages' => 0];
    if (! class_exists('WP_Query')) {
        return $empty;
    }
    $cat = sanyuan_news_category_id();
    if (! $cat) {
        return $empty;
    }
    $run = static function (int $categoryId, bool $suppress, ?string $lang = null) use ($page, $perPage): array {
        $args = [
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'posts_per_page'   => $perPage,
            'paged'            => max(1, $page),
            'orderby'          => 'date',
            'order'            => 'DESC',
            'category__in'     => [$categoryId],
            'suppress_filters' => $suppress,
        ];
        if ($lang !== null && $lang !== '') {
            $args['lang'] = $lang;
        }
        $q = new \WP_Query($args);
        $out = [
            'posts'     => $q->posts,
            'total'     => (int) $q->found_posts,
            'max_pages' => (int) $q->max_num_pages,
        ];
        wp_reset_postdata();

        return $out;
    };
    $out = $run($cat, false);
    if ($out['posts']) {
        return $out;
    }
    $enCat = sanyuan_news_en_category_id();
    if ($enCat <= 0 || $enCat === $cat) {
        return $empty;
    }

    return $run($enCat, true, function_exists('default_lang') ? default_lang() : null);
}

/** Current page number for the /news/ listing (supports /news/page/N/). */
function sanyuan_news_list_page(): int
{
    $paged = (int) get_query_var('paged');
    if ($paged < 1) {
        $paged = (int) get_query_var('page');
    }
    return max(1, $paged);
}

/** Permalink of the News page in the current language. */
function sanyuan_news_page_base_url(): string
{
    $page = function_exists('get_page_by_path')
        ? get_page_by_path(function_exists('sanyuan_page_slug') ? sanyuan_page_slug('news') : 'news')
        : null;
    $id = $page ? (int) $page->ID : 0;
    if ($id && function_exists('App\\lang_page_id')) {
        $id = lang_page_id($id);
    }
    return $id ? (string) get_permalink($id) : home_url('/news/');
}

/** URL for a paginated /news/ listing page. */
function sanyuan_news_page_url(int $page = 1): string
{
    $base = sanyuan_news_page_base_url();
    if ($page <= 1) {
        return $base;
    }
    return user_trailingslashit($base) . 'page/' . $page . '/';
}

/** Mirror-style pager (.page_con) with real links instead of javascript: AJAX. */
function sanyuan_news_pagination_bar(int $current, int $maxPages): string
{
    if ($maxPages <= 1) {
        return '';
    }

    $prev = $current <= 1
        ? '<a href="javascript:;" class="page_a page_prev disabled">&lt;</a >'
        : '<a href="' . esc_url(sanyuan_news_page_url($current - 1)) . '" class="page_a page_prev ">&lt;</a >';
    $next = $current >= $maxPages
        ? '<a href="javascript:;" class="page_a page_next disabled">&gt;</a >'
        : '<a href="' . esc_url(sanyuan_news_page_url($current + 1)) . '" class="page_a page_next ">&gt;</a >';

    $nums = '';
    $ellipsis = false;
    for ($n = 1; $n <= $maxPages; $n++) {
        if ($n === 1 || $n === $maxPages || ($n >= $current - 2 && $n <= $current + 2)) {
            if ($n === $current) {
                $nums .= '<a class="page_a page_num current" href="javascript:;">' . $n . '</a>';
            } else {
                $nums .= '<a class="page_a page_num" href="' . esc_url(sanyuan_news_page_url($n)) . '">' . $n . '</a>';
            }
            $ellipsis = false;
            continue;
        }
        if (! $ellipsis) {
            $nums .= '<span class="page_a page_ellipsis">...</span>';
            $ellipsis = true;
        }
    }

    return $prev . $nums . $next;
}

/** Build card HTML from a list inner fragment + posts. */
function sanyuan_news_cards_html(string $listInner, array $posts): string
{
    $tpl = sanyuan_news_card_template($listInner);
    if ($tpl === '') {
        return $listInner;
    }
    $cards = '';
    foreach ($posts as $p) {
        $cards .= sanyuan_news_card($tpl, $p);
    }
    return $cards;
}

/** Card display data for one news post. */
function sanyuan_news_card_data(\WP_Post $p): array
{
    $link = function_exists('get_field') ? (string) get_field('news_link', $p->ID) : '';
    if ($link === '') {
        $link = (string) get_permalink($p->ID);
    }

    $img = function_exists('get_field') ? (string) get_field('news_image', $p->ID) : '';
    if ($img === '') {
        $tid = (int) get_post_thumbnail_id($p->ID);
        if ($tid) {
            $img = (string) wp_get_attachment_image_url($tid, 'large');
        }
    }

    $excerpt = $p->post_excerpt !== ''
        ? $p->post_excerpt
        : wp_trim_words(wp_strip_all_tags($p->post_content), 40, '…');

    return [
        'link'    => $link,
        'image'   => $img,
        'title'   => get_the_title($p),
        'excerpt' => $excerpt,
        'date'    => get_the_date('Y-m-d', $p),
    ];
}

/** First <a …p_loopitem…>…</a> card in a list's inner HTML, used as a template
 *  so injected cards inherit this page's exact element classes (→ CSS works). */
function sanyuan_news_card_template(string $listInner): string
{
    return preg_match('~<a class="[^"]*\bp_loopitem\b[^"]*".*?</a>~s', $listInner, $m)
        ? $m[0] : '';
}

/** Clone the template card, swapping link / image / title / excerpt / date. */
function sanyuan_news_card(string $tpl, \WP_Post $p): string
{
    $d = sanyuan_news_card_data($p);
    $card = $tpl;

    // href in the opening <a> (first match only).
    $card = preg_replace('~href="[^"]*"~', 'href="' . esc_url($d['link']) . '"', $card, 1);

    // The single <img> (mirror_html already stripped lazy/needthumb/la).
    if ($d['image'] !== '') {
        $card = preg_replace_callback('~<img\b[^>]*>~i', function () use ($d) {
            return '<img src="' . esc_url($d['image']) . '" alt="' . esc_attr($d['title'])
                . '" title="' . esc_attr($d['title']) . '" />';
        }, $card, 1);
    }

    // Title (e_text-4), excerpt (e_richText-N … clearfix), date (e_timeFormat-N).
    $card = preg_replace_callback('~(<p class="[^"]*\be_text-4\b[^"]*">).*?(</p>)~s',
        fn ($m) => $m[1] . ' ' . esc_html($d['title']) . ' ' . $m[2], $card, 1);
    $card = preg_replace_callback('~(<div class="[^"]*\be_richText-\d+\b[^"]*\bclearfix"[^>]*>).*?(</div>)~s',
        fn ($m) => $m[1] . ' ' . esc_html($d['excerpt']) . ' ' . $m[2], $card, 1);
    $card = preg_replace_callback('~(<P class="[^"]*\be_timeFormat-\d+\b[^"]*">).*?(</P>)~s',
        fn ($m) => $m[1] . esc_html($d['date']) . $m[2], $card, 1);

    return $card;
}

/**
 * Replace baked cards inside e_loop-2. No posts ⇒ keep mirror fallback.
 * Optional pagination replaces .page_con when $maxPages is set.
 */
function sanyuan_inject_news_list(
    string $html,
    string $sectionId,
    array $posts,
    ?int $currentPage = null,
    int $maxPages = 0,
    int $total = 0
): string {
    if ($posts === [] && $total === 0) {
        return $html;
    }

    $pat = $maxPages > 0
        ? '~(id="' . preg_quote($sectionId, '~') . '".*?elem-id="e_loop-2".*?<div class="p_list">)(.*?)(</div>\s*<div class="p_page">\s*<div class="page_con">)(.*?)(</div>\s*</div>\s*</div>\s*<input type="hidden" name="_config")~s'
        : '~(id="' . preg_quote($sectionId, '~') . '".*?elem-id="e_loop-2".*?<div class="p_list">)(.*?)(</div>\s*<div class="p_page">)~s';

    $out = preg_replace_callback(
        $pat,
        function ($m) use ($posts, $currentPage, $maxPages, $sectionId) {
            $cards = sanyuan_news_cards_html($m[2], $posts);
            if ($maxPages > 0 && $currentPage !== null) {
                return $m[1] . $cards . $m[3] . sanyuan_news_pagination_bar($currentPage, $maxPages) . $m[5];
            }
            return $m[1] . $cards . $m[3];
        },
        $html,
        1
    );

    return is_string($out) ? $out : $html;
}

/** Home block: latest N posts, no pagination. */
function sanyuan_inject_news(string $html, string $sectionId, int $limit): string
{
    return sanyuan_inject_news_list($html, $sectionId, sanyuan_news_query($limit));
}

/** /news/ listing: paginated blog posts + mirror pager. */
function sanyuan_inject_news_paginated(string $html, string $sectionId, int $perPage, int $page): string
{
    $q = sanyuan_news_query_paged($page, $perPage);
    if ($q['total'] === 0) {
        return $html;
    }
    if ($q['max_pages'] > 0 && $page > $q['max_pages']) {
        $page = $q['max_pages'];
        $q    = sanyuan_news_query_paged($page, $perPage);
    }

    $html = sanyuan_inject_news_list(
        $html,
        $sectionId,
        $q['posts'],
        $page,
        $q['max_pages'],
        $q['total']
    );

    return preg_replace(
        '~(id="' . preg_quote($sectionId, '~') . '".*?<div class="e_loop-2[^"]*"[^>]*\b)needjs="true"~s',
        '$1needjs="false"',
        $html,
        1
    ) ?? $html;
}

/** Drive every News & Events list on a managed page from the blog posts. */
function sanyuan_inject_news_for(string $slug, string $html): string
{
    $targets = sanyuan_news_targets();
    if (! isset($targets[$slug])) {
        return $html;
    }
    [$sectionId, $limit] = $targets[$slug];
    if ($slug === 'news') {
        return sanyuan_inject_news_paginated($html, $sectionId, $limit, sanyuan_news_list_page());
    }
    return sanyuan_inject_news($html, $sectionId, $limit);
}

/* ===========================================================================
 *  News article DETAIL page — render a single News & Events post inside the
 *  site's own chrome (header / menu / footer / floating bar) instead of the
 *  bare Sage blog template, so it looks like the rest of the 300.cn site.
 *
 *  Shell: we reuse the post's OWN original mirror page (kept in
 *  `_sanyuan_news_src`) so its layout/imagery match the article, then swap the
 *  <title>, the visible <h1> heading and the article body with the WP post's
 *  (editable) title + content + featured image. A short/stub or brand-new post
 *  (no usable source) falls back to a known-good canonical shell.
 * ========================================================================= */

/** Canonical fallback shell (path is RELATIVE to public/site/, as mirror_html
 *  expects), a full, chromed news-article mirror page. */
const SANYUAN_NEWS_SHELL = 'NewDetails/2067258523009368064.html';

/** Mirror-relative file (under public/site/) to use as the chrome shell for a
 *  post — its own source page, else the canonical fallback. '' if none usable. */
function sanyuan_news_shell_file(int $postId): string
{
    $src   = (string) get_post_meta($postId, '_sanyuan_news_src', true);
    $cands = [];
    if ($src !== '') {
        $cands[] = ctype_digit($src)
            ? 'NewDetails/' . $src . '.html'
            : $src . '.html';
    }
    $cands[] = SANYUAN_NEWS_SHELL;

    foreach ($cands as $rel) {
        $f = get_theme_file_path('public/site/' . $rel);
        if (is_readable($f) && filesize($f) > 5000) { // skip tiny stub pages
            return $rel;
        }
    }
    return '';
}

/** Replace the inner HTML of the article-body block (the longest e_richText). */
function sanyuan_news_replace_body(string $html, string $newInner): string
{
    if (! preg_match_all(
        '~<div class="[^"]*\be_richText-\d+\b[^"]*"[^>]*>(.*?)</div>~is',
        $html, $mm, PREG_OFFSET_CAPTURE
    )) {
        return $html;
    }
    $bestIdx = -1;
    $bestLen = 0;
    foreach ($mm[1] as $i => $cap) {
        $len = strlen(trim(wp_strip_all_tags($cap[0])));
        if ($len > $bestLen) {
            $bestLen = $len;
            $bestIdx = $i;
        }
    }
    if ($bestIdx < 0 || $bestLen < 50) {
        return $html; // no obvious body block — leave the shell untouched
    }
    [$inner, $offset] = $mm[1][$bestIdx];
    return substr($html, 0, $offset) . "\n" . $newInner . "\n"
        . substr($html, $offset + strlen($inner));
}

/** Swap the shell's title/heading/body with the WP post's title + content. */
function sanyuan_news_inject_post(string $html, int $postId): string
{
    $title = get_the_title($postId);

    $html = preg_replace('~<title>.*?</title>~is',
        '<title>' . esc_html($title) . '</title>', $html, 1);
    $html = preg_replace('~(<meta\s+property="og:title"\s+content=")[^"]*(")~i',
        '${1}' . esc_attr($title) . '$2', $html, 1);
    $html = preg_replace('~(<meta\s+name="twitter:title"\s+content=")[^"]*(")~i',
        '${1}' . esc_attr($title) . '$2', $html, 1);

    // Visible heading (first e_h1-N element).
    $html = preg_replace_callback('~(<h1 class="[^"]*\be_h1-\d+\b[^"]*">).*?(</h1>)~is',
        fn ($m) => $m[1] . ' ' . esc_html($title) . ' ' . $m[2], $html, 1);

    // Body = featured image (if any) + the post content.
    $bodyInner = '';
    $thumb = get_the_post_thumbnail_url($postId, 'large');
    if ($thumb) {
        $bodyInner .= '<p><img src="' . esc_url($thumb) . '" alt="' . esc_attr($title)
            . '" style="max-width:100%;height:auto;" /></p>' . "\n";
    }
    $bodyInner .= apply_filters('the_content', get_post_field('post_content', $postId));

    return sanyuan_news_replace_body($html, $bodyInner);
}

/**
 * Section id PREFIXES of the shared "detail page" bottom widgets that are not
 * part of the article itself — the 300.cn "latest news" hover showcases
 * (c_effect_040_P_050-*). They carry a slide-on-hover caption (e_container-22)
 * that doesn't behave out of its original context and clutters the article, so
 * we drop them from our news detail. The article banner (c_banner_028) and body
 * (c_new_detail_043) are kept, as are the site header/footer chrome.
 */
function sanyuan_news_widget_prefixes(): array
{
    return ['c_effect_040_P_050'];
}

/** Remove whole <div id="…">…</div> sections whose id starts with a prefix. */
function sanyuan_news_strip_sections(string $html, array $idPrefixes): string
{
    $changed = true;
    while ($changed) {
        $changed = false;
        if (! preg_match_all('~<div id="(c_[^"]+)"~', $html, $mm)) {
            break;
        }
        foreach (array_unique($mm[1]) as $id) {
            foreach ($idPrefixes as $pre) {
                if (strncmp($id, $pre, strlen($pre)) !== 0) {
                    continue;
                }
                [$s, $e] = section_bounds($html, $id);
                if ($s !== null && $e !== null) {
                    $html = substr($html, 0, $s) . substr($html, $e);
                    $changed = true;
                }
                break;
            }
        }
    }
    return $html;
}

/** True when a mirror relpath is a news-article template (NewDetails/*). */
function sanyuan_is_news_detail_mirror(string $path): bool
{
    return (bool) preg_match('~^NewDetails/\d+$~', trim($path, '/'));
}

/** Build the chromed detail HTML for a News & Events post ('' ⇒ no shell). */
function sanyuan_render_news_detail(int $postId): string
{
    $rel = sanyuan_news_shell_file($postId);
    if ($rel === '') {
        return '';
    }
    $html = mirror_html($rel);
    if ($html === '') {
        return '';
    }
    if (function_exists('get_field')) {
        $html = inject_header($html);
        $html = inject_footer($html);
        $html = inject_chrome($html);
    }
    // Drop the shared landing-page widgets that aren't part of this article.
    $html = sanyuan_news_strip_sections($html, sanyuan_news_widget_prefixes());
    $html = sanyuan_news_inject_post($html, $postId);
    return sanyuan_finalize_links($html);
}

/* Serve single News & Events posts through the chromed detail renderer. */
add_action('template_redirect', function () {
    if (is_admin() || ! is_singular('post')) {
        return;
    }
    $id = (int) get_queried_object_id();
    if (! $id || ! has_category(SANYUAN_NEWS_CAT, $id)) {
        return; // not a news post ⇒ let WordPress use the normal template
    }

    global $post;
    $post = get_post($id);
    setup_postdata($post);

    $html = sanyuan_render_news_detail($id);
    if ($html === '') {
        wp_reset_postdata();
        return; // no usable shell ⇒ fall back to the Sage blog template
    }

    nocache_headers();
    echo $html;
    exit;
}, 0);

/*
 * /m/NewDetails/* — intercept before mirror-extra.php (-1): chromed WP detail for
 * imported posts; strip c_effect_040_P_050 hover widgets on raw NewDetails mirrors.
 */
add_action('template_redirect', function () {
    if (! function_exists('get_query_var') || ! function_exists('App\\sanyuan_content_relpaths')) {
        return;
    }
    $path = trim((string) get_query_var(SANYUAN_MIRROR_QV), '/');
    if ($path === '' || strpos($path, '..') !== false
        || ! preg_match('~^[A-Za-z0-9_/.-]+$~', $path)
        || ! isset(sanyuan_content_relpaths()[$path])) {
        return;
    }

    $post         = sanyuan_find_news_by_src(sanyuan_news_src_from_mirror_path($path));
    $isNewDetails = sanyuan_is_news_detail_mirror($path);

    if (! $post && ! $isNewDetails) {
        return; // non-news mirror — let mirror-extra.php handle it
    }

    if ($post && has_category(SANYUAN_NEWS_CAT, $post->ID)) {
        $html = sanyuan_render_news_detail($post->ID);
        if ($html !== '') {
            nocache_headers();
            echo $html;
            exit;
        }
    }

    if (! $isNewDetails) {
        return;
    }

    $html = mirror_html($path . '.html');
    if ($html === '') {
        return;
    }
    if (function_exists('get_field')) {
        $html = inject_header($html);
        $html = inject_footer($html);
        $html = inject_chrome($html);
    }
    $html = sanyuan_news_strip_sections($html, sanyuan_news_widget_prefixes());
    $html = sanyuan_finalize_links($html);

    nocache_headers();
    echo $html;
    exit;
}, -2);
