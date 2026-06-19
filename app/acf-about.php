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

add_action('acf/init', function () {
    if (! function_exists('acf_add_local_field_group')) {
        return;
    }

    $about = function_exists('get_page_by_path') ? get_page_by_path('about') : null;
    $aboutId = $about ? (int) $about->ID : 0;
    $location = [];
    if ($aboutId && function_exists('pll_get_post') && function_exists('pll_languages_list')) {
        foreach ((array) pll_languages_list(['fields' => 'slug']) as $lng) {
            $tid = pll_get_post($aboutId, $lng);
            if ($tid) {
                $location[] = [['param' => 'page', 'operator' => '==', 'value' => (string) $tid]];
            }
        }
    }
    if (! $location) {
        $location = [[['param' => 'page', 'operator' => '==', 'value' => $aboutId ? (string) $aboutId : 'about']]];
    }

    $fields = [];

    $fields[] = ['key' => 'field_tab_visibility', 'label' => 'Show / Hide', 'type' => 'tab', 'placement' => 'top'];
    foreach (about_sections() as $sid => $name) {
        $fields[] = [
            'key' => 'field_show_' . $sid,
            'label' => $name,
            'name' => 'show_' . $sid,
            'type' => 'true_false',
            'ui' => 1,
            'default_value' => 1,
            'message' => 'Show this section',
        ];
    }

    $fields[] = ['key' => 'field_tab_hero', 'label' => section_tab_label(1), 'type' => 'tab', 'placement' => 'top'];
    $fields[] = ['key' => 'field_about_hero_image', 'name' => 'about_hero_image', 'label' => 'Main image',
                 'type' => 'image', 'return_format' => 'url', 'preview_size' => 'medium'];
    $fields[] = ['key' => 'field_about_hero_title', 'name' => 'about_hero_title', 'label' => 'Title (part 1)',
                 'type' => 'text'];
    $fields[] = ['key' => 'field_about_hero_highlight', 'name' => 'about_hero_highlight', 'label' => 'Highlight (red)',
                 'type' => 'text'];
    $fields[] = ['key' => 'field_about_hero_title_end', 'name' => 'about_hero_title_end', 'label' => 'Title (part 2)',
                 'type' => 'text'];
    $fields[] = ['key' => 'field_about_hero_desc', 'name' => 'about_hero_desc', 'label' => 'Description',
                 'type' => 'textarea', 'rows' => 3];

    acf_add_local_field_group([
        'key' => 'group_sanyuan_about',
        'title' => 'About page content',
        'fields' => $fields,
        'location' => $location,
        'menu_order' => 0,
        'style' => 'default',
    ]);
});
