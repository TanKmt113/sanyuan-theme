<?php

/**
 * Extract Chinese ACF field values from public/site/zh/*.html mirrors.
 *
 * Aligns each field to its EN counterpart by matching the EN fragment inside the
 * same section, then reads the ZH HTML at the same rich-text / text node index.
 */

if (! function_exists('update_field')) {
    return;
}

/** Mirror filename for a managed page slug. */
function seed_zh_mirror_filename(string $slug): string
{
    $files = App\sanyuan_page_files();

    return $files[$slug] ?? ($slug . '.html');
}

/** Normalize text for loose EN-field matching inside a section. */
function seed_zh_normalize_plain(string $html): string
{
    return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($html)) ?? '');
}

/** Format a wysiwyg ACF value like the EN mirror originals. */
function seed_zh_format_wysiwyg(string $inner): string
{
    $inner = trim($inner);
    if ($inner === '') {
        return '';
    }

    return "\n    " . $inner . "\n\n";
}

/** Format a plain text ACF value like the EN mirror originals. */
function seed_zh_format_text(string $inner): string
{
    $inner = trim(wp_strip_all_tags($inner));
    if ($inner === '' || $inner === '这里是占位文字') {
        return '';
    }

    return "\n        " . $inner . "\n";
}

/** Find the rich-text node index whose EN content matches a field original. */
function seed_zh_match_richtext_index(array $enNodes, string $original): int
{
    $want = seed_zh_normalize_plain($original);
    if ($want === '') {
        return -1;
    }
    $needle = mb_substr($want, 0, 40);
    foreach ($enNodes as $i => $html) {
        $got = seed_zh_normalize_plain((string) $html);
        if ($got === '' || $got === '这里是占位文字') {
            continue;
        }
        if ($got === $want || ($needle !== '' && mb_strpos($got, $needle) !== false)) {
            return (int) $i;
        }
    }

    return -1;
}

/** Find the <img> index in an EN section whose src matches an image field original. */
function seed_zh_match_image_index(string $enSectionHtml, string $original): int
{
    $file = basename($original);
    if ($file === '') {
        return -1;
    }
    if (! preg_match_all('~<img\b[^>]*\bsrc="([^"]*)"~i', $enSectionHtml, $m)) {
        return -1;
    }
    foreach ($m[1] as $i => $src) {
        if (str_contains((string) $src, $file)) {
            return (int) $i;
        }
    }

    return -1;
}

/** Find the text-node index whose EN content matches a field original. */
function seed_zh_match_text_index(array $enNodes, string $original): int
{
    $want = seed_zh_normalize_plain($original);
    if ($want === '') {
        return -1;
    }
    foreach ($enNodes as $i => $html) {
        $got = seed_zh_normalize_plain((string) $html);
        if ($got === '' || $got === '这里是占位文字') {
            continue;
        }
        if ($got === $want) {
            return (int) $i;
        }
    }

    return -1;
}

/** Collect rich-text / text nodes inside one mirror section. */
function seed_zh_section_nodes(string $sectionHtml): array
{
    preg_match_all(
        '~<div class="e_richText[^"]*"[^>]*>\s*(.*?)\s*</div>~s',
        $sectionHtml,
        $rich
    );
    preg_match_all(
        '~<p class="e_text[^"]*"[^>]*>\s*(.*?)\s*</p>~s',
        $sectionHtml,
        $text
    );

    return [$rich[1] ?? [], $text[1] ?? []];
}

/** Field keys to skip when seeding a page slug. */
function seed_zh_skip_field_keys(string $slug): array
{
    $skip = [];
    if ($slug === 'news' && function_exists('App\\sanyuan_news_list_section_field_names')) {
        $skip = array_flip(App\sanyuan_news_list_section_field_names());
    }

    return $skip;
}

/** Read an EN ACF/meta value for copying onto the ZH page. */
function seed_zh_en_field_value(int $enId, string $key)
{
    $value = get_field($key, $enId);
    if ($value !== null && $value !== '' && $value !== false) {
        return $value;
    }

    $meta = get_post_meta($enId, $key, true);

    return ($meta !== '' && $meta !== false) ? $meta : null;
}

/** Attachment ID for an EN image field (ACF stores ID in post meta). */
function seed_zh_en_attachment_id(int $enId, string $key): int
{
    $meta = get_post_meta($enId, $key, true);
    if (is_numeric($meta) && (int) $meta > 0) {
        return (int) $meta;
    }
    $value = get_field($key, $enId);
    if (is_numeric($value) && (int) $value > 0) {
        return (int) $value;
    }
    if (is_array($value) && ! empty($value['ID'])) {
        return (int) $value['ID'];
    }

    return 0;
}

/** About Section 1: EN mirror uses @@SECTION_HERO@@ — extract hero fields from ZH only. */
function seed_zh_extract_about_hero_section(string $zhHtml): array
{
    $sectionId = 'c_static_001-17621535739280';
    [$a, $b]   = App\section_bounds($zhHtml, $sectionId);
    if ($a === null || $b === null) {
        return [];
    }
    $sec = substr($zhHtml, $a, $b - $a);
    [$rich, $text] = seed_zh_section_nodes($sec);
    $values        = [];

    if (isset($rich[0]) && trim((string) $rich[0]) !== '') {
        $values['about_text_1'] = seed_zh_format_wysiwyg((string) $rich[0]);
    }

    preg_match_all(
        '~<p class="e_text-2[0-9][^"]*"[^>]*>\s*(.*?)\s*</p>~s',
        $sec,
        $extraText
    );
    foreach ($extraText[1] ?? [] as $chunk) {
        $formatted = seed_zh_format_text((string) $chunk);
        if ($formatted !== '') {
            $values['about_text_2'] = $formatted;
            break;
        }
    }
    if (! isset($values['about_text_2']) && isset($text[0])) {
        $formatted = seed_zh_format_text((string) $text[0]);
        if ($formatted !== '') {
            $values['about_text_2'] = $formatted;
        }
    }

    return $values;
}

/** Extract zh values for one page from EN + ZH mirror HTML. */
function seed_zh_extract_mirror_values(string $slug): array
{
    $enFile = get_theme_file_path('public/site/' . seed_zh_mirror_filename($slug));
    $zhFile = get_theme_file_path('public/site/zh/' . seed_zh_mirror_filename($slug));
    if (! is_readable($enFile) || ! is_readable($zhFile)) {
        return [];
    }

    $enHtml = (string) file_get_contents($enFile);
    $zhHtml = (string) file_get_contents($zhFile);
    $skip   = seed_zh_skip_field_keys($slug);
    $bySec  = [];

    foreach (App\page_fields_data($slug) as $field) {
        $key = $field['key'] ?? '';
        if ($key === '' || isset($skip[$key])) {
            continue;
        }
        $bySec[$field['section']][] = $field;
    }

    $values = [];
    if ($slug === 'about') {
        $values = array_merge($values, seed_zh_extract_about_hero_section($zhHtml));
    }

    foreach ($bySec as $sectionId => $fields) {
        [$enA, $enB] = App\section_bounds($enHtml, $sectionId);
        [$zhA, $zhB] = App\section_bounds($zhHtml, $sectionId);
        if ($enA === null || $enB === null || $zhA === null || $zhB === null) {
            continue;
        }

        $enSec = substr($enHtml, $enA, $enB - $enA);
        $zhSec = substr($zhHtml, $zhA, $zhB - $zhA);
        [$enRich, $enText] = seed_zh_section_nodes($enSec);
        [$zhRich, $zhText] = seed_zh_section_nodes($zhSec);

        foreach ($fields as $field) {
            $key  = $field['key'];
            $type = $field['type'] ?? 'text';
            $orig = (string) ($field['original'] ?? '');

            if ($type === 'image') {
                continue; // copied from EN attachment IDs later
            }
            if ($orig === '') {
                continue;
            }

            if ($type === 'wysiwyg') {
                $idx = seed_zh_match_richtext_index($enRich, $orig);
                if ($idx >= 0 && isset($zhRich[$idx])) {
                    $val = seed_zh_format_wysiwyg((string) $zhRich[$idx]);
                    if ($val !== '') {
                        $values[$key] = $val;
                    }
                }
                continue;
            }

            $idx = seed_zh_match_text_index($enText, $orig);
            if ($idx >= 0 && isset($zhText[$idx])) {
                $val = seed_zh_format_text((string) $zhText[$idx]);
                if ($val !== '') {
                    $values[$key] = $val;
                }
            }
        }
    }

    return $values;
}

/** Copy section show/hide toggles from EN page to ZH page. */
function seed_zh_copy_section_toggles(int $enId, int $zhId, string $slug): array
{
    $seeded = [];
    foreach (array_keys(App\page_section_labels($slug)) as $sectionId) {
        $name  = 'show_' . $sectionId;
        $value = get_field($name, $enId);
        if ($value === null) {
            continue;
        }
        sanyuan_seed_set_acf_field($zhId, $name, 'field_' . $name, $value);
        $seeded[] = "$slug:$name";
    }

    return $seeded;
}

/** Copy image fields from EN page (shared media) to ZH page. */
function seed_zh_copy_image_fields(int $enId, int $zhId, string $slug): array
{
    $seeded = [];
    $skip   = seed_zh_skip_field_keys($slug);
    foreach (App\page_fields_data($slug) as $field) {
        if (($field['type'] ?? '') !== 'image') {
            continue;
        }
        $key = $field['key'] ?? '';
        if ($key === '' || isset($skip[$key])) {
            continue;
        }
        $enVal = seed_zh_en_attachment_id($enId, $key);
        if ($enVal <= 0) {
            continue;
        }
        sanyuan_seed_set_acf_field($zhId, $key, 'field_' . $key, $enVal);
        $seeded[] = "$slug:$key";
    }

    return $seeded;
}

/** Seed all mirror-derived ACF values onto the ZH translation of one page. */
function seed_zh_page_mirror_acf(string $slug): array
{
    $en = get_page_by_path(App\sanyuan_page_slug($slug));
    if (! $en || ! function_exists('pll_get_post')) {
        return [];
    }
    $zhId = (int) (pll_get_post($en->ID, 'zh') ?: 0);
    if ($zhId <= 0) {
        return [];
    }

    $seeded = [];
    foreach (seed_zh_extract_mirror_values($slug) as $key => $value) {
        sanyuan_seed_set_acf_field($zhId, $key, 'field_' . $key, $value);
        $seeded[] = "$slug:$key";
    }
    $seeded = array_merge(
        $seeded,
        seed_zh_copy_image_fields((int) $en->ID, $zhId, $slug),
        seed_zh_copy_section_toggles((int) $en->ID, $zhId, $slug)
    );

    return $seeded;
}

/** Seed mirror ACF for every managed page (except Home — already curated). */
function seed_zh_all_mirror_acf(bool $includeHome = false): array
{
    $seeded = [];
    foreach (array_keys(App\sanyuan_pages()) as $slug) {
        if (! $includeHome && $slug === 'home') {
            continue;
        }
        $seeded = array_merge($seeded, seed_zh_page_mirror_acf($slug));
    }

    return $seeded;
}
