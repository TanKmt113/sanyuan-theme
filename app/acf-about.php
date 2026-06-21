<?php

/**
 * ACF fields for the About page (clean, curated — rebuilt section by section).
 *
 * - "Show / Hide" tab: on/off switch per section (hides the whole section).
 * - One tab per rebuilt section with proper fields. Sections not yet rebuilt
 *   still render from the original mirror and have no fields here.
 *
 * Rendering lives in app/routes.php (about_html + the section Blade partials).
 */

namespace App;

const ABOUT_HERO_SECTION_ID = 'c_static_001-17621535739280';

/** Curated About hero fields (Section 1). */
function about_hero_field_names(): array
{
    return [
        'about_hero_image',
        'about_hero_title',
        'about_hero_highlight',
        'about_hero_title_end',
        'about_hero_desc',
    ];
}

/** Legacy mirror JSON fields replaced by about_hero_* (hide from admin + inject). */
function about_mirror_hero_field_keys(): array
{
    return ['about_text_1', 'about_text_2', 'about_img_1', 'about_img_2'];
}

/** Swap mirror hero markup for the ACF-driven Blade partial (empty fields OK). */
function sanyuan_inject_about_hero(string $html, int $pageId): string
{
    if ($pageId <= 0 || ! function_exists('view')) {
        return $html;
    }

    [$a, $b] = section_bounds($html, ABOUT_HERO_SECTION_ID);
    if ($a === null || $b === null) {
        return $html;
    }

    $hero = view('about.hero', ['pageId' => $pageId])->render();

    return substr($html, 0, $a) . $hero . substr($html, $b);
}

/** Sections in document order: id => tab label. */
function about_sections(): array
{
    $ids = [
        'c_static_001-17621535739280',
        'c_static_001-1762157949289',
        'c_static_001-17622212746450',
        'c_static_001-17622242783260',
        'c_static_001-1762235322264',
        'c_static_001-1762237127549',
        'c_static_001-1762237957873',
        'c_category_302-1765775922043',
        'c_static_001-17622382811530',
    ];
    $sections = [];
    foreach ($ids as $i => $sid) {
        $sections[$sid] = section_tab_label($i + 1);
    }

    return $sections;
}

/** Hero fields spliced into group_sanyuan_about (Section 1 tab). */
function about_hero_acf_field_defs(): array
{
    $fields = [
        ['key' => 'field_about_hero_note', 'name' => '_about_hero_note', 'label' => '', 'type' => 'message',
         'message' => 'Section 1 uses these curated fields (replaces the old mirror fragments for this block).',
         'new_lines' => 'wpautop', 'esc_html' => 0],
        ['key' => 'field_about_hero_image', 'name' => 'about_hero_image', 'label' => 'Main image',
         'type' => 'image', 'return_format' => 'url', 'preview_size' => 'medium'],
        ['key' => 'field_about_hero_title', 'name' => 'about_hero_title', 'label' => 'Title (part 1)',
         'type' => 'text'],
        ['key' => 'field_about_hero_highlight', 'name' => 'about_hero_highlight', 'label' => 'Highlight (red)',
         'type' => 'text'],
        ['key' => 'field_about_hero_title_end', 'name' => 'about_hero_title_end', 'label' => 'Title (part 2)',
         'type' => 'text'],
        ['key' => 'field_about_hero_desc', 'name' => 'about_hero_desc', 'label' => 'Description',
         'type' => 'textarea', 'rows' => 3],
    ];
    if (! function_exists('acf_validate_field')) {
        return $fields;
    }

    return array_map('acf_validate_field', $fields);
}

/** Drop the old split hero group if it was registered in a prior deploy. */
add_action('acf/init', function (): void {
    if (function_exists('acf_remove_local_field_group')) {
        acf_remove_local_field_group('group_sanyuan_about_hero');
    }
}, 5);
