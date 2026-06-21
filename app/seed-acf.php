<?php

/**
 * Sync mirror originals → ACF (only fields that are still empty).
 *
 * Run once:
 *   php -r 'define("WP_USE_THEMES",false); require "wp-load.php";
 *           require "wp-content/themes/sanyuan-theme/app/seed-acf.php";'
 */

if (! function_exists('update_field')) {
    return;
}

/** Theme asset URL from a mirror "../assets_img/…" path. */
function sanyuan_seed_mirror_asset_url(string $original): string
{
    if (preg_match('~(?:\.\./)*assets_img/(.+)$~', $original, $m)) {
        return rtrim(get_theme_file_uri('public/assets_img'), '/') . '/' . $m[1];
    }

    return $original;
}

/** Whether an ACF field is unset (incl. broken image id 0). */
function sanyuan_acf_field_is_empty($value): bool
{
    return $value === null || $value === '' || $value === false || $value === '0' || $value === 0;
}

/** Whether a file/image field needs seeding (empty or saved as URL string). */
function sanyuan_acf_media_field_needs_fix($value): bool
{
    if (sanyuan_acf_field_is_empty($value)) {
        return true;
    }

    return is_string($value) && preg_match('~^https?://~i', $value);
}

/** Find an existing media-library attachment by filename. */
function sanyuan_seed_find_upload_attachment(string $basename): int
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
        '%' . $wpdb->esc_like($basename)
    ));
}

/** Home page IDs for every Polylang translation (EN, ZH, …). */
function sanyuan_all_lang_home_ids(): array
{
    $seed = get_page_by_path('home');
    if (! $seed) {
        return [];
    }

    $ids = [];
    if (function_exists('pll_get_post') && function_exists('pll_languages_list')) {
        foreach ((array) pll_languages_list(['fields' => 'slug']) as $lng) {
            $tid = (int) (pll_get_post($seed->ID, $lng) ?: 0);
            if ($tid > 0) {
                $ids[] = $tid;
            }
        }
    }
    if ($ids === []) {
        $ids[] = (int) $seed->ID;
    }

    return array_values(array_unique($ids));
}

/** Default-language Home page ID. */
function sanyuan_default_home_id(): int
{
    $ids = sanyuan_all_lang_home_ids();
    if ($ids === []) {
        return 0;
    }
    if (function_exists('pll_get_post') && function_exists('default_lang')) {
        $seed = get_page_by_path('home');
        if ($seed) {
            return (int) (pll_get_post($seed->ID, default_lang()) ?: $ids[0]);
        }
    }

    return $ids[0];
}

function sanyuan_home_hero_field_keys(): array
{
    return [
        'home_hero_video'   => 'field_home_hero_video',
        'home_hero_poster'  => 'field_home_hero_poster',
        'home_hero_img1'    => 'field_home_hero_img1',
        'home_hero_img2'    => 'field_home_hero_img2',
        'home_hero_img3'    => 'field_home_hero_img3',
        'home_hero_tagline' => 'field_home_hero_tagline',
    ];
}

/** Persist an ACF value with its reference meta so wp-admin pickers recognize it. */
function sanyuan_seed_set_acf_field($target, string $name, string $fieldKey, $value): void
{
    if (is_int($target)) {
        update_post_meta($target, $name, $value);
        update_post_meta($target, '_' . $name, $fieldKey);

        return;
    }
    update_field($fieldKey, $value, $target);
}

/** Import a theme asset into the media library; return attachment ID (0 on failure). */
function sanyuan_seed_attach_theme_image(string $basename): int
{
    $existing = sanyuan_seed_find_upload_attachment($basename);
    if ($existing > 0) {
        return $existing;
    }

    $path = get_theme_file_path('public/assets_img/' . $basename);
    if (! is_readable($path)) {
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = wp_tempnam($basename);
    if (! $tmp || ! @copy($path, $tmp)) {
        return 0;
    }

    $filetype = wp_check_filetype($basename);
    $file     = [
        'name'     => $basename,
        'type'     => $filetype['type'] ?? 'image/png',
        'tmp_name' => $tmp,
        'error'    => 0,
        'size'     => (int) @filesize($tmp),
    ];

    $attachId = media_handle_sideload($file, 0);
    @unlink($tmp);

    return is_wp_error($attachId) ? 0 : (int) $attachId;
}

/** Whether ACF reference meta is missing or wrong (wp-admin picker stays empty). */
function sanyuan_acf_field_needs_reference_fix(int $pageId, string $name, string $fieldKey): bool
{
    $ref = get_post_meta($pageId, '_' . $name, true);

    return $ref === '' || $ref === false || $ref !== $fieldKey;
}

/** Write attachment ID to a file/image ACF field (fixes URL-string saves too). */
function sanyuan_seed_media_field(string $name, int $attachId, int $pageId, string $fieldKey = ''): ?string
{
    $fieldKey = $fieldKey !== '' ? $fieldKey : (sanyuan_home_hero_field_keys()[$name] ?? 'field_' . $name);
    if ($attachId <= 0 || $fieldKey === '') {
        return null;
    }
    $needsValue = sanyuan_acf_media_field_needs_fix(get_field($name, $pageId));
    $needsRef   = sanyuan_acf_field_needs_reference_fix($pageId, $name, $fieldKey);
    if (! $needsValue && ! $needsRef) {
        return null;
    }
    sanyuan_seed_set_acf_field($pageId, $name, $fieldKey, $attachId);

    return $name;
}

/** Write a scalar ACF value when empty or missing its reference meta. */
function sanyuan_seed_field_repair(string $name, $value, int $pageId, string $fieldKey): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $needsValue = sanyuan_acf_field_is_empty(get_field($name, $pageId))
        || sanyuan_acf_media_field_needs_fix(get_field($name, $pageId));
    $needsRef = sanyuan_acf_field_needs_reference_fix($pageId, $name, $fieldKey);
    if (! $needsValue && ! $needsRef) {
        return null;
    }
    sanyuan_seed_set_acf_field($pageId, $name, $fieldKey, $value);

    return $name;
}

/** Write $value when the ACF field on $target is empty; return field name if written. */
function sanyuan_seed_field_if_empty(string $name, $value, $target, string $fieldKey = ''): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (! sanyuan_acf_field_is_empty(get_field($name, $target))) {
        return null;
    }
    sanyuan_seed_set_acf_field($target, $name, $fieldKey !== '' ? $fieldKey : 'field_' . $name, $value);

    return $name;
}

/** Mirror JSON fragments (app/page-fields/*.json) → per-page ACF. */
function sanyuan_seed_page_mirror_fields(int $pageId, string $slug): array
{
    $seeded = [];
    foreach (App\page_fields_data($slug) as $f) {
        $key  = $f['key'] ?? '';
        $orig = (string) ($f['original'] ?? '');
        if ($key === '' || $orig === '') {
            continue;
        }
        $value = ($f['type'] ?? '') === 'image'
            ? sanyuan_seed_attach_theme_image(basename($orig))
            : $orig;
        if (($f['type'] ?? '') === 'image' && (int) $value <= 0) {
            continue;
        }
        if (sanyuan_seed_field_if_empty($key, $value, $pageId, 'field_' . $key)) {
            $seeded[] = "$slug:$key";
        }
    }

    return $seeded;
}

/** Canonical attachment IDs for Home Section 1 hero media. */
function sanyuan_seed_home_hero_attachment_ids(): array
{
    return [
        'home_hero_video' => sanyuan_seed_find_upload_attachment('best-Bus-Cable-Fire-Resistant-Cable-china-HangZhou-SANYUAN.mp4'),
    ];
}

/** Overlay logo attachment IDs (imported from theme assets). */
function sanyuan_seed_home_hero_logo_attachment_ids(): array
{
    return [
        'home_hero_img1' => sanyuan_seed_attach_theme_image('09c23881-5922-455e-a748-8a5baebc702e_01847b.png'),
        'home_hero_img2' => sanyuan_seed_attach_theme_image('dc6742f2-476a-4c33-aa63-1d724593a93a_7a8c8e.png'),
        'home_hero_img3' => sanyuan_seed_attach_theme_image('e3c2b84a-b443-4c6c-89cf-e70f6a56a445_fffb9d.png'),
    ];
}

/** Home Section 1 hero — media as attachment IDs + text slogan. */
function sanyuan_seed_home_hero(int $homeId): array
{
    $keys = sanyuan_home_hero_field_keys();
    $seeded = [];
    foreach (sanyuan_seed_home_hero_attachment_ids() as $name => $attachId) {
        if ($field = sanyuan_seed_media_field($name, $attachId, $homeId, $keys[$name])) {
            $seeded[] = "home:$field";
        }
    }
    foreach (sanyuan_seed_home_hero_logo_attachment_ids() as $name => $attachId) {
        if ($field = sanyuan_seed_media_field($name, $attachId, $homeId, $keys[$name])) {
            $seeded[] = "home:$field";
        }
    }
    if (sanyuan_seed_field_repair(
        'home_hero_tagline',
        'To be the most trusted provider of communication products and solutions for global customers',
        $homeId,
        $keys['home_hero_tagline']
    )) {
        $seeded[] = 'home:home_hero_tagline';
    }

    return $seeded;
}

/** Copy hero media to a translated Home page (tagline stays per language). */
function sanyuan_seed_home_hero_media_copy(int $targetId): array
{
    $keys = sanyuan_home_hero_field_keys();
    $seeded = [];
    foreach (sanyuan_seed_home_hero_attachment_ids() as $name => $attachId) {
        if ($field = sanyuan_seed_media_field($name, $attachId, $targetId, $keys[$name])) {
            $seeded[] = "home-zh:$field";
        }
    }
    foreach (sanyuan_seed_home_hero_logo_attachment_ids() as $name => $attachId) {
        if ($field = sanyuan_seed_media_field($name, $attachId, $targetId, $keys[$name])) {
            $seeded[] = "home-zh:$field";
        }
    }

    return $seeded;
}

/** Home body CTA buttons — label + link pairs from the original mirror. */
function sanyuan_seed_home_ctas(int $homeId): array
{
    $pageUrl = static function (string $slug): string {
        $page = get_page_by_path($slug);

        return $page ? (string) get_permalink($page) : '';
    };
    $pairs = [
        'home_cta_partner' => ['View all products', $pageUrl('product')],
        'home_cta_lab'     => ['View cable lab', $pageUrl('cable-lab-overview')],
        'home_cta_esg'     => ['View ESG management', $pageUrl('esg')],
        'home_cta_docs'    => ['View documentation', $pageUrl('support')],
    ];
    $seeded = [];
    foreach ($pairs as $prefix => [$label, $link]) {
        if (sanyuan_seed_field_if_empty($prefix . '_label', $label, $homeId)) {
            $seeded[] = "home:{$prefix}_label";
        }
        if ($link !== '' && sanyuan_seed_field_if_empty($prefix . '_link', $link, $homeId)) {
            $seeded[] = "home:{$prefix}_link";
        }
    }

    return $seeded;
}

/** About rebuilt hero (Section 1) — separate from mirror fragment fields. */
function sanyuan_seed_about_hero(int $aboutId): array
{
    $fields = [
        'about_hero_title'     => 'Leading Cable Manufacturer with',
        'about_hero_highlight' => 'over 30 Years',
        'about_hero_title_end' => 'of Excellence',
        'about_hero_desc'      => 'We are motivated to continuously improve our individual and company performance to deliver maximum value to our customers.',
        'about_hero_image'     => sanyuan_seed_attach_theme_image('7c7d81ee-69d7-4e0f-929c-750b92257d3f_13e34b.jpeg'),
    ];
    $seeded = [];
    foreach ($fields as $name => $value) {
        if ($name === 'about_hero_image') {
            if ($field = sanyuan_seed_media_field($name, (int) $value, $aboutId, 'field_about_hero_image')) {
                $seeded[] = "about:$field";
            }
            continue;
        }
        if (sanyuan_seed_field_if_empty($name, $value, $aboutId, 'field_' . $name)) {
            $seeded[] = "about:$name";
        }
    }

    return $seeded;
}

/** Import overlay logos to Media Library for Home (EN + translations). */
function sanyuan_seed_home_hero_logos_for_pages(): array
{
    $logoIds = sanyuan_seed_home_hero_logo_attachment_ids();
    if (min($logoIds) <= 0) {
        return [];
    }

    $keys   = sanyuan_home_hero_field_keys();
    $seeded = [];
    foreach (sanyuan_all_lang_home_ids() as $pageId) {
        foreach ($logoIds as $name => $attachId) {
            if ($field = sanyuan_seed_media_field($name, $attachId, $pageId, $keys[$name])) {
                $seeded[] = "$pageId:$field";
            }
        }
    }

    return $seeded;
}

/** True when every Home overlay logo field stores a media attachment ID. */
function sanyuan_home_hero_logos_ready(): bool
{
    foreach (sanyuan_all_lang_home_ids() as $pageId) {
        foreach (['home_hero_img1', 'home_hero_img2', 'home_hero_img3'] as $name) {
            $val = get_post_meta($pageId, $name, true);
            if (! is_numeric($val) || (int) $val <= 0) {
                return false;
            }
        }
    }

    return sanyuan_all_lang_home_ids() !== [];
}

/** Full Home Section 1 import: logos, video, slogan, CTAs (EN + ZH). */
function sanyuan_import_home_hero_all(): array
{
    $hid = sanyuan_default_home_id();
    if ($hid <= 0) {
        return [];
    }

    $seeded = array_merge(
        sanyuan_seed_home_hero_logos_for_pages(),
        sanyuan_seed_home_hero($hid),
        sanyuan_seed_home_ctas($hid)
    );

    if (function_exists('pll_get_post')) {
        $zhId = (int) (pll_get_post($hid, 'zh') ?: 0);
        if ($zhId > 0) {
            $seeded = array_merge($seeded, sanyuan_seed_home_hero_media_copy($zhId));
            $zhFields = [
                'home_hero_tagline'      => '成为全球客户最值得信赖的通信产品与解决方案提供商',
                'home_cta_partner_label' => '查看全部产品',
                'home_cta_lab_label'     => '查看电缆实验室',
                'home_cta_esg_label'     => '查看ESG管理',
                'home_cta_docs_label'    => '查看文档',
            ];
            $keys = sanyuan_home_hero_field_keys();
            foreach ($zhFields as $name => $value) {
                $key = $keys[$name] ?? 'field_' . $name;
                if ($field = sanyuan_seed_field_repair($name, $value, $zhId, $key)) {
                    $seeded[] = "home-zh:$field";
                }
            }
            if (function_exists('seed_zh_page_url')) {
                foreach ([
                    'home_cta_partner_link' => 'product',
                    'home_cta_lab_link'     => 'cable-lab-overview',
                    'home_cta_esg_link'     => 'esg',
                    'home_cta_docs_link'    => 'support',
                ] as $name => $slug) {
                    $url = seed_zh_page_url($slug);
                    if ($url !== '' && sanyuan_seed_field_repair($name, $url, $zhId, 'field_' . $name)) {
                        $seeded[] = "home-zh:$name";
                    }
                }
            }
        }
    }

    if (sanyuan_home_hero_logos_ready()) {
        update_option('sanyuan_home_hero_logos_v2', 1, false);
    }

    return $seeded;
}

if (! defined('SANYUAN_SEED_INCLUDE_ONLY')) {
$seeded = [];

foreach (App\sanyuan_pages() as $slug => $_rel) {
    $page = get_page_by_path(App\sanyuan_page_slug($slug));
    if (! $page) {
        continue;
    }
    $seeded = array_merge($seeded, sanyuan_seed_page_mirror_fields((int) $page->ID, $slug));
}

$homeId = sanyuan_default_home_id();
if ($homeId > 0) {
    $seeded = array_merge($seeded, sanyuan_seed_home_hero($homeId), sanyuan_seed_home_ctas($homeId));
    if (function_exists('pll_get_post')) {
        $zhId = (int) (pll_get_post($homeId, 'zh') ?: 0);
        if ($zhId > 0) {
            $seeded = array_merge($seeded, sanyuan_seed_home_hero_media_copy($zhId));
            $zhTagline = get_field('home_hero_tagline', $zhId);
            if (is_string($zhTagline) && $zhTagline !== '') {
                if ($field = sanyuan_seed_field_repair('home_hero_tagline', $zhTagline, $zhId, 'field_home_hero_tagline')) {
                    $seeded[] = "home-zh:$field";
                }
            }
        }
    }
}

$about = get_page_by_path('about');
if ($about) {
    $seeded = array_merge($seeded, sanyuan_seed_about_hero((int) $about->ID));
}

define('SANYUAN_SEED_CONTACT_INCLUDE_ONLY', true);
require_once __DIR__ . '/seed-contact-form.php';
$seeded = array_merge($seeded, sanyuan_seed_contact_form_all());

if (defined('WP_CLI') || PHP_SAPI === 'cli') {
    echo $seeded === []
        ? "ACF already synced — nothing to seed.\n"
        : "Seeded " . count($seeded) . " field(s):\n  " . implode("\n  ", $seeded) . "\n";
}
}
