<?php

/**
 * Home page extras: hero video, CTA buttons, footer products button, News tab UX.
 */

namespace App;

/** One CTA: label + link pair (spliced into the matching section tab). */
function home_extras_cta_pair(string $id, string $btnLabel, string $defaultLabel): array
{
    return [
        ['key' => 'field_' . $id . '_note', 'name' => '_' . $id . '_note', 'label' => '', 'type' => 'message',
         'message' => 'Button <strong>' . esc_html($defaultLabel) . '</strong> in this section.',
         'new_lines' => 'wpautop', 'esc_html' => 0],
        ['key' => 'field_' . $id . '_lbl', 'name' => $id . '_label',
         'label' => 'Button label', 'type' => 'text', 'placeholder' => $defaultLabel,
         'wrapper' => ['width' => '50']],
        ['key' => 'field_' . $id . '_lnk', 'name' => $id . '_link', 'label' => 'Button link',
         'type' => 'text', 'placeholder' => 'https://… or /zh/product/',
         'wrapper' => ['width' => '50']],
    ];
}

/** Insert each home CTA after the content field it belongs to (same section tab in admin). */
function home_extras_splice_cta_fields(array $fields): array
{
    $placements = [
        // Partner block — sau đoạn mô tả, trước ảnh icon nút.
        'home_text_10' => home_extras_cta_pair('home_cta_partner', 'View all products', 'View all products'),
        // Quality block — sau home_text_16 (trước khối ESG trong cùng tab).
        'home_text_16' => home_extras_cta_pair('home_cta_lab', 'View cable lab', 'View cable lab'),
        'home_text_19' => home_extras_cta_pair('home_cta_esg', 'View ESG management', 'View ESG management'),
        // Document Support — sau nội dung text, trước ảnh minh họa.
        'home_text_26' => home_extras_cta_pair('home_cta_docs', 'View documentation', 'View documentation'),
    ];
    foreach (array_reverse($placements, true) as $anchor => $ctaFields) {
        foreach ($fields as $i => $f) {
            if (($f['name'] ?? '') !== $anchor) {
                continue;
            }
            array_splice($fields, $i + 1, 0, home_extras_validate_fields($ctaFields));
            break;
        }
    }
    return $fields;
}

/** Ensure dynamically spliced local fields have ACF defaults (value, name, …). */
function home_extras_validate_fields(array $fields): array
{
    if (! function_exists('acf_validate_field')) {
        return $fields;
    }
    return array_map('acf_validate_field', $fields);
}

/** Extra ACF fields spliced into the Home page group (hero + body CTAs). */
function sanyuan_home_extras_field_defs(): array
{
    return home_extras_validate_fields([
        ['key' => 'field_tab_home_hero', 'label' => 'Section 1', 'type' => 'tab', 'placement' => 'top'],
        ['key' => 'field_home_hero_video', 'name' => 'home_hero_video', 'label' => 'Video (MP4)',
         'type' => 'file', 'return_format' => 'url', 'library' => 'all', 'mime_types' => 'mp4'],
        ['key' => 'field_home_hero_poster', 'name' => 'home_hero_poster', 'label' => 'Poster (optional)',
         'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium'],
        ['key' => 'field_home_hero_overlay_note', 'name' => '_home_hero_overlay_note', 'label' => '', 'type' => 'message',
         'message' => 'Animated overlay (logos + slogan) on top of the video — built by <code>CircleExpandAnimation</code> on page load.',
         'new_lines' => 'wpautop', 'esc_html' => 0],
        ['key' => 'field_home_hero_img1', 'name' => 'home_hero_img1', 'label' => 'Overlay — logo 1',
         'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium', 'wrapper' => ['width' => '33']],
        ['key' => 'field_home_hero_img2', 'name' => 'home_hero_img2', 'label' => 'Overlay — logo 2',
         'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium', 'wrapper' => ['width' => '33']],
        ['key' => 'field_home_hero_img3', 'name' => 'home_hero_img3', 'label' => 'Overlay — logo 3 (slide)',
         'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium', 'wrapper' => ['width' => '34']],
        ['key' => 'field_home_hero_tagline', 'name' => 'home_hero_tagline', 'label' => 'Overlay slogan',
         'type' => 'textarea', 'rows' => 3,
         'placeholder' => 'To be the most trusted provider of communication products and solutions for global customers'],
    ]);
}

/** Footer "View all products" button (Options). */
function sanyuan_footer_cta_field_defs(): array
{
    return home_extras_validate_fields([
        ['key' => 'field_tab_f_cta', 'label' => 'Section 5', 'type' => 'tab', 'placement' => 'top'],
        ...home_extras_cta_pair('footer_products_cta', 'Footer — View all products', 'View all products'),
    ]);
}

/** Fallback news card fields — hidden in admin when WP posts drive the list. */
function sanyuan_home_news_fallback_field_names(): array
{
    return [
        'home_text_28', 'home_text_29', 'home_text_30', 'home_text_31',
        'home_text_32', 'home_text_33', 'home_text_34', 'home_text_35',
        'home_img_21', 'home_img_22', 'home_img_23', 'home_img_24',
    ];
}

/** CTA field keys grouped by anchor field name. */
function home_extras_cta_field_keys(): array
{
    return [
        'home_text_10' => ['field_home_cta_partner_note', 'field_home_cta_partner_lbl', 'field_home_cta_partner_lnk'],
        'home_text_16' => ['field_home_cta_lab_note', 'field_home_cta_lab_lbl', 'field_home_cta_lab_lnk'],
        'home_text_19' => ['field_home_cta_esg_note', 'field_home_cta_esg_lbl', 'field_home_cta_esg_lnk'],
        'home_text_26' => ['field_home_cta_docs_note', 'field_home_cta_docs_lbl', 'field_home_cta_docs_lnk'],
    ];
}

/** Pull fields out of a list by key (preserves order within $keys). */
function home_extras_extract_fields_by_keys(array $fields, array $keys): array
{
    $wanted = array_flip($keys);
    $out    = [];
    $rest   = [];
    foreach ($fields as $f) {
        $k = $f['key'] ?? '';
        if ($k !== '' && isset($wanted[$k])) {
            $out[] = $f;
        } else {
            $rest[] = $f;
        }
    }

    return [$out, $rest];
}

/** Re-order registered Home hero + CTA fields (acf/load_fields — display only). */
function sanyuan_reorder_home_extras_fields(array $fields): array
{
    $heroKeys = array_map(static fn ($f) => $f['key'] ?? '', sanyuan_home_extras_field_defs());
    $heroKeys = array_values(array_filter($heroKeys));
    [$hero, $fields] = home_extras_extract_fields_by_keys($fields, $heroKeys);

    $idx = count($fields);
    foreach ($fields as $k => $f) {
        if (($f['type'] ?? '') === 'tab' && strpos((string) ($f['key'] ?? ''), 'field_tab_vis_') !== 0) {
            $idx = $k;
            break;
        }
    }
    if ($hero !== []) {
        array_splice($fields, $idx, 0, $hero);
    }

    foreach (array_reverse(home_extras_cta_field_keys(), true) as $anchor => $ctaKeys) {
        [$cta, $fields] = home_extras_extract_fields_by_keys($fields, $ctaKeys);
        if ($cta === []) {
            continue;
        }
        foreach ($fields as $i => $f) {
            if (($f['name'] ?? '') === $anchor) {
                array_splice($fields, $i + 1, 0, $cta);
                break;
            }
        }
    }

    return $fields;
}

/** @deprecated Use sanyuan_reorder_home_extras_fields(); kept for acf-pages.php if patched. */
function sanyuan_splice_home_extras_fields(array $fields): array
{
    return sanyuan_reorder_home_extras_fields($fields);
}

add_filter('acf/load_fields', function (array $fields, array $parent): array {
    $key = $parent['key'] ?? '';
    if ($key === 'group_sanyuan_home') {
        $fields = sanyuan_reorder_home_extras_fields($fields);

        $newsTab = 'field_tab_c_static_001-17604262155250';
        foreach ($fields as $k => $f) {
            if (($f['key'] ?? '') !== $newsTab) {
                continue;
            }
            array_splice($fields, $k + 1, 0, home_extras_validate_fields([[
                'key' => 'field_home_news_note', 'name' => '_home_news_note', 'label' => '', 'type' => 'message',
                'message' => '<strong>News list is not edited here.</strong> The home page shows the <strong>4 latest posts</strong> '
                    . 'from the <em>News &amp; Events</em> category.<br><br>'
                    . '→ Add / edit posts under <strong>Posts</strong> (title, content, featured image, excerpt).<br>'
                    . '→ Optional per post: <em>Card image</em>, <em>Detail link</em> (sidebar).<br><br>'
                    . 'Only the <strong>section title</strong> field below belongs to this page.',
                'new_lines' => 'wpautop', 'esc_html' => 0,
            ]]));
            break;
        }

        // Gỡ field fallback mirror (home_text_28…35, home_img_21…24) — không dùng khi đã có blog.
        $drop = array_flip(sanyuan_home_news_fallback_field_names());
        $fields = array_values(array_filter($fields, static function ($f) use ($drop) {
            return ! isset($drop[$f['name'] ?? '']);
        }));
        foreach ($fields as $i => $f) {
            if (($f['name'] ?? '') === 'home_text_27') {
                $fields[$i]['label']    = 'Section title (News & Events)';
                $fields[$i]['instructions'] = 'Edit the large heading above the news list only.';
                break;
            }
        }

        $docTab = 'field_tab_c_static_001-1760425468148';
        foreach ($fields as $k => $f) {
            if (($f['key'] ?? '') !== $docTab) {
                continue;
            }
            array_splice($fields, $k + 1, 0, home_extras_validate_fields([[
                'key' => 'field_home_doc_note', 'name' => '_home_doc_note', 'label' => '', 'type' => 'message',
                'message' => 'The <strong>3 cards</strong> below (images + titles + descriptions) rebuild the '
                    . 'Document Support grid. Empty slots are omitted (no mirror fallback).',
                'new_lines' => 'wpautop', 'esc_html' => 0,
            ]]));
            break;
        }

        $certTab = 'field_tab_c_static_001-1760411525813';
        foreach ($fields as $k => $f) {
            if (($f['key'] ?? '') !== $certTab) {
                continue;
            }
            array_splice($fields, $k + 1, 0, home_extras_validate_fields([[
                'key' => 'field_home_cert_note', 'name' => '_home_cert_note', 'label' => '', 'type' => 'message',
                'message' => 'Certification icons <strong>home_img_9 … home_img_15</strong> map to the horizontal '
                    . 'badge row (e_loop-15). Cleared images are omitted.',
                'new_lines' => 'wpautop', 'esc_html' => 0,
            ]]));
            break;
        }
    }
    if ($key === 'group_sanyuan_footer') {
        $ctaKeys = ['field_tab_f_cta', 'field_footer_products_cta_note', 'field_footer_products_cta_lbl', 'field_footer_products_cta_lnk'];
        $cta     = [];
        $fields  = array_values(array_filter($fields, static function ($f) use (&$cta, $ctaKeys) {
            if (in_array($f['key'] ?? '', $ctaKeys, true)) {
                $cta[] = $f;
                return false;
            }
            return true;
        }));
        if ($cta !== []) {
            foreach ($fields as $k => $f) {
                if (($f['key'] ?? '') === 'field_tab_f_copyright') {
                    array_splice($fields, $k, 0, $cta);
                    break;
                }
            }
        }
    }
    return $fields;
}, 10, 2);

/**
 * One-time: import hero overlay logos into Media Library when wp-admin loads
 * (CLI cannot write to uploads/ — web request runs as www).
 */
add_action('admin_init', function (): void {
    if (! current_user_can('edit_pages') || get_option('sanyuan_home_hero_logos_v2')) {
        return;
    }

    define('SANYUAN_SEED_INCLUDE_ONLY', true);
    require_once get_theme_file_path('app/seed-acf.php');
    require_once get_theme_file_path('app/seed-options-zh.php');
    sanyuan_import_home_hero_all();
}, 20);

/** HTTP trigger for hero import (web SAPI = www, can write uploads). Dev/local only. */
add_action('init', function (): void {
    if (($_GET['sanyuan_hero_seed'] ?? '') !== '1') {
        return;
    }
    if (! function_exists('get_field')) {
        return;
    }

    define('SANYUAN_SEED_INCLUDE_ONLY', true);
    require_once get_theme_file_path('app/seed-acf.php');
    require_once get_theme_file_path('app/seed-options-zh.php');
    $seeded = sanyuan_import_home_hero_all();

    status_header(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SANYUAN hero import OK\n";
    echo 'ready=' . (sanyuan_home_hero_logos_ready() ? 'yes' : 'no') . "\n";
    echo 'fields=' . count($seeded) . "\n";
    foreach ($seeded as $line) {
        echo "  $line\n";
    }
    exit;
}, 1);

/** Register footer CTA fields on the static group so ACF Options can persist them. */
add_action('acf/init', function (): void {
    if (! function_exists('acf_add_local_field')) {
        return;
    }
    foreach (sanyuan_footer_cta_field_defs() as $field) {
        $field['parent'] = 'group_sanyuan_footer';
        acf_add_local_field($field);
    }
}, 20);

/**
 * Register Home Section 1 + body CTA fields on the Home group so ACF persists
 * saves from wp-admin (fields injected only via acf/load_fields are not saved).
 */
add_action('acf/init', function (): void {
    if (! function_exists('acf_add_local_field')) {
        return;
    }
    $parent = 'group_sanyuan_home';
    foreach (sanyuan_home_extras_field_defs() as $field) {
        $field['parent'] = $parent;
        acf_add_local_field($field);
    }
    foreach ([
        'home_cta_partner' => ['View all products', 'View all products'],
        'home_cta_lab'     => ['View cable lab', 'View cable lab'],
        'home_cta_esg'     => ['View ESG management', 'View ESG management'],
        'home_cta_docs'    => ['View documentation', 'View documentation'],
    ] as $id => [$btnLabel, $defaultLabel]) {
        foreach (home_extras_cta_pair($id, $btnLabel, $defaultLabel) as $field) {
            $field['parent'] = $parent;
            acf_add_local_field($field);
        }
    }
}, 20);

/**
 * Swap label and/or href on a mirror CTA (<a class="e_button-XX">…<span>…</span>).
 * Empty ACF label/link clears the button (no mirror href/text fallback).
 */
function mirror_button_apply(
    string $html,
    string $btnClass,
    $label,
    $link
): string {
    $pat = '~(<a class="[^"]*\b' . preg_quote($btnClass, '~') . '\b[^"]*"[^>]*href=")([^"]*)("'
         . '[^>]*>.*?<span>)(.*?)(</span>)~s';
    if (! preg_match($pat, $html, $m, PREG_OFFSET_CAPTURE)) {
        return $html;
    }

    $labelStr = is_string($label) && trim($label) !== '' ? trim($label) : '';
    $linkStr  = is_string($link) && trim($link) !== '' ? trim($link) : '';
    $href     = $linkStr !== '' ? esc_attr($linkStr) : '#';
    $inner    = $labelStr !== '' ? esc_html($labelStr) : '';

    if ($href === $m[2][0] && $inner === $m[4][0]) {
        return $html;
    }

    $out = $m[1][0] . $href . $m[3][0] . $inner . $m[5][0];
    return substr($html, 0, $m[0][1]) . $out . substr($html, $m[0][1] + strlen($m[0][0]));
}

/** Append ?v=mtime so browser/CDN pick up replaced media after admin edits. */
function home_hero_media_url_with_bust(string $url, int $attachmentId = 0): string
{
    if ($url === '') {
        return '';
    }
    if ($attachmentId <= 0) {
        return $url;
    }
    $ver = get_post_modified_time('U', true, $attachmentId);
    if (! $ver) {
        return $url;
    }

    return add_query_arg('v', (string) $ver, $url);
}

/** Resolve an ACF file/image field to a public URL (attachment ID, array, or URL string). */
function home_hero_acf_media_url(int $pageId, string $field): string
{
    $val = get_field($field, $pageId);
    if (is_array($val)) {
        $url = $val['url'] ?? '';
        $id  = isset($val['ID']) ? (int) $val['ID'] : 0;

        return is_string($url) && $url !== ''
            ? home_hero_media_url_with_bust($url, $id)
            : '';
    }
    if (is_numeric($val) && (int) $val > 0) {
        $id  = (int) $val;
        $url = wp_get_attachment_url($id);

        return is_string($url) && $url !== ''
            ? home_hero_media_url_with_bust($url, $id)
            : '';
    }

    return is_string($val) ? $val : '';
}

/** 1×1 transparent GIF — truthy in JS so CircleExpandAnimation skips hard-coded mirror defaults. */
function home_hero_empty_image_url(): string
{
    return sanyuan_empty_image_url();
}

/** True once the field has been saved in wp-admin (including cleared image/file). */
function home_hero_field_is_set(int $pageId, string $field): bool
{
    return sanyuan_acf_field_is_set($field, $pageId);
}

/**
 * Overlay logo URL for CircleExpandAnimation — empty ACF ⇒ hide (no mirror default).
 */
function home_hero_overlay_image_url(int $pageId, string $field): string
{
    $url = home_hero_acf_media_url($pageId, $field);

    return $url !== '' ? $url : home_hero_empty_image_url();
}

/** CircleExpandAnimation config from ACF; empty fields hide overlay slots. */
function home_hero_overlay_config(int $pageId, bool $ndHide): array
{
    $cfg  = [];
    $hide = [];
    foreach (['img1' => 'home_hero_img1', 'img2' => 'home_hero_img2', 'img3' => 'home_hero_img3'] as $jsKey => $field) {
        $url = home_hero_overlay_image_url($pageId, $field);
        $cfg[$jsKey] = $url;
        if ($url === home_hero_empty_image_url()) {
            $hide[] = $jsKey;
        }
    }
    $text = get_field('home_hero_tagline', $pageId);
    $cfg['text'] = is_string($text) && $text !== '' ? $text : '';
    if ($hide !== []) {
        $cfg['_hide'] = $hide;
    }

    return $cfg;
}

/** Hide overlay slots the editor cleared (transparent placeholder still loads otherwise). */
function home_hero_overlay_hide_css(array $hideKeys): string
{
    if ($hideKeys === []) {
        return '';
    }
    $map = ['img1' => '1', 'img2' => '2', 'img3' => '3'];
    $css = '';
    foreach ($hideKeys as $key) {
        if (! isset($map[$key])) {
            continue;
        }
        $n = $map[$key];
        $css .= '#c_static_001-1764813278526 .showTextBox-img' . $n
            . '{display:none!important;visibility:hidden!important}';
    }

    return $css;
}

/** Pass overlay config into CircleExpandAnimation(…, config) in the page footer script. */
function home_hero_overlay_inject_script(string $html, array $config): string
{
    $hide = $config['_hide'] ?? [];
    unset($config['_hide']);
    if ($config === [] && $hide === []) {
        return $html;
    }
    if ($hide !== []) {
        $css = home_hero_overlay_hide_css($hide);
        if ($css !== '') {
            $html = sanyuan_inject_head_style($html, $css, 'sanyuan-home-hero-overlay');
        }
    }
    if ($config === []) {
        return $html;
    }
    $json = wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sel  = '#c_static_001-1764813278526 .e_container-1 .cbox-1-0';
    $qsel = preg_quote($sel, '~');
    $repl = 'new CircleExpandAnimation(\'' . $sel . '\', ' . $json . ');';
    // Match bare call or replace stale config from a cached HTML response.
    $pat  = '~new CircleExpandAnimation\(\'' . $qsel . '\'(?:,\s*\{.*?\})?\);~s';
    $out  = preg_replace($pat, $repl, $html, 1);
    if (! is_string($out) || ! str_contains($out, 'CircleExpandAnimation(\'' . $sel . '\', ')) {
        $out = preg_replace(
            '~new CircleExpandAnimation\(\'' . $qsel . '\'\);~',
            $repl,
            $html,
            1
        );
    }

    return is_string($out) ? $out : $html;
}

/** Inject hero video + body CTA buttons on the home page. */
function sanyuan_inject_home_extras(string $html, int $pageId): string
{
    if (! function_exists('get_field') || $pageId <= 0) {
        return $html;
    }

    $nd = function_exists('App\\is_default_lang') && ! is_default_lang();

    $video = home_hero_acf_media_url($pageId, 'home_hero_video');
    if ($video !== '') {
        $html = preg_replace(
            '~(<div id="c_videoContainer-1764741858033"[^>]*>.*?<source src=")[^"]+(")~s',
            '$1' . esc_url($video) . '$2',
            $html,
            1
        ) ?? $html;
    } else {
        $html = sanyuan_inject_head_style(
            $html,
            '#c_videoContainer-1764741858033{display:none!important;visibility:hidden!important}',
            'sanyuan-home-hero-video-cleared'
        );
    }

    $poster = home_hero_acf_media_url($pageId, 'home_hero_poster');
    $html = preg_replace(
        '~(<div id="c_videoContainer-1764741858033"><video\b[^>]*\bposter=")[^"]*(")~',
        '$1' . ($poster !== '' ? esc_url($poster) : '') . '$2',
        $html,
        1
    ) ?? $html;

    $html = home_hero_overlay_inject_script($html, home_hero_overlay_config($pageId, $nd));

    $ctas = [
        ['e_button-6', 'home_cta_partner'],
        ['e_button-37', 'home_cta_lab'],
        ['e_button-40', 'home_cta_esg'],
        ['e_button-10', 'home_cta_docs'],
    ];
    foreach ($ctas as [$cls, $prefix]) {
        $html = mirror_button_apply(
            $html,
            $cls,
            get_field($prefix . '_label', $pageId),
            get_field($prefix . '_link', $pageId)
        );
    }

    return $html;
}

/** Footer-scoped products CTA (Options store). */
function sanyuan_inject_footer_products_cta(string $foot): string
{
    if (! function_exists('get_field')) {
        return $foot;
    }
    return mirror_button_apply(
        $foot,
        'e_button-50',
        get_field('footer_products_cta_label', 'option'),
        get_field('footer_products_cta_link', 'option')
    );
}

const HOME_CERT_SECTION = 'c_static_001-1760411525813';
const HOME_DOC_SECTION  = 'c_static_001-1760425468148';

/** Plain-text home field for card titles/descriptions. */
function sanyuan_home_plain_field(int $pageId, string $field): string
{
    if (! function_exists('get_field')) {
        return '';
    }
    $value = get_field($field, $pageId);
    if (! is_string($value) || $value === '') {
        return '';
    }

    return function_exists(__NAMESPACE__ . '\\sanyuan_normalize_field_value')
        ? (string) sanyuan_normalize_field_value($value, 'text')
        : trim(wp_strip_all_tags($value));
}

/** Certification icon URLs saved on the home page (home_img_9 … home_img_15). */
function sanyuan_home_cert_icon_fields(): array
{
    return [
        'home_img_9', 'home_img_10', 'home_img_11', 'home_img_12',
        'home_img_13', 'home_img_14', 'home_img_15',
    ];
}

/** Document Support card field triplets (image, title, description). */
function sanyuan_home_document_card_fields(): array
{
    return [
        ['home_img_18', 'home_text_21', 'home_text_22'],
        ['home_img_19', 'home_text_23', 'home_text_24'],
        ['home_img_20', 'home_text_25', 'home_text_26'],
    ];
}

/** Rebuild e_loop-15 cert icons from ACF (empty repeater clears mirror icons). */
function sanyuan_inject_home_cert_icons(string $html, int $pageId): string
{
    $items = '';
    foreach (sanyuan_home_cert_icon_fields() as $field) {
        $url = sanyuan_acf_image_url(get_field($field, $pageId));
        if ($url === '') {
            continue;
        }
        $items .= '<div class="cbox-15 p_loopitem"><div class="e_image-16 s_img">'
            . '<img src="' . esc_url($url) . '" alt="certification" title="certification"/>'
            . '</div></div>';
    }

    return sanyuan_replace_section_loop_plist($html, HOME_CERT_SECTION, 'e_loop-15', $items);
}

/** Rebuild Document Support cards from ACF (empty fields render empty cards). */
function sanyuan_inject_home_document_cards(string $html, int $pageId): string
{
    $cards = '';
    foreach (sanyuan_home_document_card_fields() as [$imgField, $titleField, $descField]) {
        $title = sanyuan_home_plain_field($pageId, $titleField);
        $desc  = sanyuan_home_plain_field($pageId, $descField);
        $img   = sanyuan_acf_image_url(get_field($imgField, $pageId));
        if ($title === '' && $desc === '' && $img === '') {
            continue;
        }
        $alt   = $title !== '' ? $title : 'document';
        $imgHtml = $img !== ''
            ? '<img src="' . esc_url($img) . '" alt="' . esc_attr($alt) . '" title="' . esc_attr($alt) . '"/>'
            : '';
        $cards .= '<div class="cbox-2 p_loopitem"><div class="e_image-3 s_img">' . $imgHtml . '</div>'
            . '<p class="e_text-4 s_title2">' . esc_html($title) . '</p>'
            . '<hr class="e_line-7 s_line" />'
            . '<p class="e_text-6 s_title2">' . esc_html($desc) . '</p></div>';
    }

    return sanyuan_replace_section_loop_plist($html, HOME_DOC_SECTION, 'e_loop-2', $cards);
}

/** Drive home cert + document loops from ACF; pair with sanyuan_static_mirror_loops(home). */
function sanyuan_inject_home_static_loops(string $html, int $pageId): string
{
    if ($pageId <= 0 || ! function_exists('get_field')) {
        return $html;
    }

    $html = sanyuan_inject_home_cert_icons($html, $pageId);

    return sanyuan_inject_home_document_cards($html, $pageId);
}
