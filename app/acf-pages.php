<?php

/**
 * ACF fields for ALL mirror pages + the shared Header/Footer — CONTENT-INJECTION.
 *
 * Pages render their ORIGINAL mirror markup byte-for-byte (original CSS + GSAP
 * animations). Nothing is rebuilt; each ACF field maps 1:1 to one original
 * fragment listed in app/page-fields/<slug>.json (About: app/about-fields.json,
 * shared chrome: app/page-fields/_chrome.json). app/routes.php swaps a fragment
 * ONLY when its field is non-empty, so empty fields = identical to the original.
 *
 *  - one field group per page, located to that WP page (per-page content).
 *  - one ACF Options page "Header & Footer" whose fields are injected into EVERY
 *    managed page (edit once, applies everywhere).
 */

namespace App;

const CHROME_OPTIONS_SLUG = 'sanyuan-header-footer';

/** slug => fragment-definition JSON (relative to the theme). */
function sanyuan_pages(): array
{
    return [
        'home'                     => 'app/page-fields/home.json',
        'about'                    => 'app/about-fields.json',
        'product'                  => 'app/page-fields/product.json',
        'news'                     => 'app/page-fields/news.json',
        'support'                  => 'app/page-fields/support.json',
        'contact'                  => 'app/page-fields/contact.json',
        'esg'                      => 'app/page-fields/esg.json',
        'cable-compliance'         => 'app/page-fields/cable-compliance.json',
        'cable-lab-overview'       => 'app/page-fields/cable-lab-overview.json',
        'cable-testing-inspection' => 'app/page-fields/cable-testing-inspection.json',
    ];
}

/** WordPress page slug for a managed page (slugs match). */
function sanyuan_page_slug(string $slug): string
{
    return $slug;
}

/** Load a JSON fragment file (cached). */
function sanyuan_load_json(string $rel): array
{
    static $cache = [];
    if (array_key_exists($rel, $cache)) {
        return $cache[$rel];
    }
    $file = get_theme_file_path($rel);
    return $cache[$rel] = ($file && is_readable($file))
        ? (json_decode(file_get_contents($file), true) ?: [])
        : [];
}

/** Per-page fragment definitions. */
function page_fields_data(string $slug): array
{
    $map = sanyuan_pages();
    return isset($map[$slug]) ? sanyuan_load_json($map[$slug]) : [];
}

/** Shared Header/Footer fragment definitions. */
function chrome_fields_data(): array
{
    return sanyuan_load_json('app/page-fields/_chrome.json');
}

/** Shared-chrome section id => label. Header and Footer now have their own
 *  structured groups; only the small floating contact bar stays flat here. */
function chrome_section_labels(): array
{
    return [
        'c_static_001-17641411302650' => section_tab_label(1),
    ];
}

/** Original menu tree (labels + WP page slug) used as the default for the Header
 *  menu repeater. Mirrors the original site navigation exactly. */
function header_default_menu(): array
{
    return [
        ['label' => 'Who we are', 'slug' => 'about', 'children' => [
            ['label' => 'About us', 'slug' => 'about'],
            ['label' => 'ESG', 'slug' => 'esg'],
            ['label' => 'News & Events', 'slug' => 'news'],
        ]],
        ['label' => 'Cable Lab', 'slug' => 'cable-lab-overview', 'children' => [
            ['label' => 'Cable Lab Overview', 'slug' => 'cable-lab-overview'],
            ['label' => 'Cable Testing & Inspection', 'slug' => 'cable-testing-inspection'],
            ['label' => 'Cable Compliance', 'slug' => 'cable-compliance'],
        ]],
        ['label' => 'Support', 'slug' => 'support', 'children' => []],
        ['label' => 'Contact', 'slug' => 'contact', 'children' => []],
        ['label' => 'Products', 'slug' => 'product', 'children' => []],
    ];
}

/** Tab label: "Section 1", "Section 2", … */
function section_tab_label(int $n): string
{
    return 'Section ' . $n;
}

/** Extra content sections prepended before mirror sections (home hero = 1 mirror tab). */
function page_extra_section_count(string $slug): int
{
    return $slug === 'home' ? 1 : 0;
}

/** section id => "Section N" (document order; home reserves Section 1 for hero). */
function page_section_labels(string $slug): array
{
    $order = [];
    $i     = 0;
    foreach (page_fields_data($slug) as $f) {
        $sid = $f['section'];
        if (! isset($order[$sid])) {
            $order[$sid] = ++$i;
        }
    }
    $offset = page_extra_section_count($slug);
    $labels = [];
    foreach ($order as $sid => $n) {
        $labels[$sid] = section_tab_label($n + $offset);
    }
    return $labels;
}

add_action('acf/init', function () {
    if (! function_exists('acf_add_local_field_group')) {
        return;
    }

    $excerpt = function (string $s, int $len = 160): string {
        $t = html_entity_decode(wp_strip_all_tags($s), ENT_QUOTES, 'UTF-8');
        $t = trim(preg_replace('/\s+/', ' ', $t));
        if (function_exists('mb_strlen')) {
            return mb_strlen($t) > $len ? mb_substr($t, 0, $len) . '…' : $t;
        }
        return strlen($t) > $len ? substr($t, 0, $len) . '…' : $t;
    };

    // Build the full field list (visibility tab + a tab per section) for a group.
    $buildFields = function (array $data, array $labels, string $token) use ($excerpt): array {
        $bySection = [];
        foreach ($data as $f) {
            $bySection[$f['section']][] = $f;
        }
        $fields = [];
        $fields[] = ['key' => 'field_tab_vis_' . $token, 'label' => 'Show / Hide', 'type' => 'tab', 'placement' => 'top'];
        foreach ($labels as $sid => $name) {
            $fields[] = [
                'key' => 'field_show_' . $sid, 'name' => 'show_' . $sid, 'label' => $name,
                'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'message' => 'Show this section',
            ];
        }
        foreach ($labels as $sid => $name) {
            $fields[] = ['key' => 'field_tab_' . $sid, 'label' => $name, 'type' => 'tab', 'placement' => 'top'];
            $imgN = $txtN = 0;
            foreach ($bySection[$sid] ?? [] as $f) {
                $key  = $f['key'];
                $orig = (string) ($f['original'] ?? '');
                if ($f['type'] === 'image') {
                    $imgN++;
                    // Label = the image's alt/title if the JSON carries one, else
                    // a numbered "Ảnh N"; the preview + filename hint do the rest.
                    $alt = trim((string) ($f['label'] ?? $f['alt'] ?? ''));
                    $fields[] = [
                        'key' => 'field_' . $key, 'name' => $key,
                        'label' => '🖼 ' . ($alt !== '' ? $excerpt($alt, 50) : 'Image ' . $imgN),
                        'type' => 'image', 'return_format' => 'url', 'preview_size' => 'medium',
                    ];
                } else {
                    $txtN++;
                    // Use a textarea (raw, preserves HTML) for rich/multiline
                    // fragments; a plain text input for simple one-liners.
                    $trim     = trim($orig);
                    $multiline = $f['type'] === 'wysiwyg'
                        || strpos($trim, '<') !== false
                        || strpos($trim, "\n") !== false;
                    // Self-describing label = the original text itself (so the
                    // editor sees WHICH text they're editing), not a generic
                    // "Đoạn/Chữ N". Falls back to numbered label if empty.
                    $lbl = $excerpt($orig, 70);
                    $fields[] = array_filter([
                        'key' => 'field_' . $key, 'name' => $key,
                        'label' => $lbl !== '' ? $lbl : (($multiline ? 'Block ' : 'Text ') . $txtN),
                        'type' => $multiline ? 'textarea' : 'text',
                        'rows' => $multiline ? 4 : null,
                        'placeholder' => $excerpt($orig, $multiline ? 220 : 120),
                        'instructions' => $multiline ? 'Keep HTML tags; change text only.' : null,
                    ], fn ($v) => $v !== null);
                }
            }
        }
        return $fields;
    };

    // --- per-page groups ---------------------------------------------------
    foreach (sanyuan_pages() as $slug => $rel) {
        $data = page_fields_data($slug);
        if (! $data) {
            continue;
        }
        $page   = get_page_by_path(sanyuan_page_slug($slug));
        $pageId = $page ? (int) $page->ID : 0;
        // Đa ngôn ngữ: hiện field group trên page của MỌI ngôn ngữ (mỗi bản dịch là
        // 1 post riêng → ACF lưu giá trị riêng theo ngôn ngữ). Mỗi clause là 1 "OR".
        $location = [];
        if ($pageId && function_exists('pll_get_post') && function_exists('pll_languages_list')) {
            foreach ((array) pll_languages_list(['fields' => 'slug']) as $lng) {
                $tid = pll_get_post($pageId, $lng);
                if ($tid) {
                    $location[] = [['param' => 'page', 'operator' => '==', 'value' => (string) $tid]];
                }
            }
        }
        if (! $location) {
            $location = [[['param' => 'page', 'operator' => '==', 'value' => $pageId ? (string) $pageId : $slug]]];
        }
        $fields = $buildFields($data, page_section_labels($slug), md5($slug));

        // Home: put the "featured categories" repeater INSIDE the Featured
        // Product tab (one place, no separate group). Inserted right after that
        // section's tab field so it sits at the top of that tab.
        if ($slug === 'home' && function_exists('App\\sanyuan_home_featured_field')) {
            $tabKey = 'field_tab_c_static_001-1760408218122';
            $idx = null;
            foreach ($fields as $k => $ff) {
                if (($ff['key'] ?? '') === $tabKey) {
                    $idx = $k;
                    break;
                }
            }
            $repeater = sanyuan_home_featured_field();
            if ($idx !== null) {
                array_splice($fields, $idx + 1, 0, [$repeater]);
            } else {
                $fields[] = $repeater;
            }
        }

        acf_add_local_field_group([
            'key'   => 'group_sanyuan_' . str_replace('-', '_', $slug),
            'title' => 'Page content — ' . ($page ? $page->post_title : ucfirst($slug)),
            'fields' => $fields,
            'location' => $location,
            'menu_order' => 0, 'style' => 'default',
        ]);
    }

    // --- shared Header / Footer (ACF Options page) -------------------------
    if (function_exists('acf_add_options_page')) {
        acf_add_options_page([
            'page_title' => 'Header & Footer',
            'menu_title' => 'Header & Footer',
            'menu_slug'  => CHROME_OPTIONS_SLUG,
            'icon_url'   => 'dashicons-align-center',
            'position'   => 59,
            'capability' => 'edit_theme_options',
            'redirect'   => false,
        ]);

        // Đa ngôn ngữ: với mỗi ngôn ngữ PHỤ (vd zh) thêm 1 Options page riêng lưu
        // vào post_id 'options_<lang>' để biên tập Header/Footer ngôn ngữ đó. Mọi
        // nhóm chrome hiển thị trên cả các trang này (location = $chromeLoc).
        // Front-end đọc đúng store theo ngôn ngữ qua filter acf/validate_post_id
        // (xem app/i18n.php).
        $chromeLoc = [[['param' => 'options_page', 'operator' => '==', 'value' => CHROME_OPTIONS_SLUG]]];
        if (function_exists('pll_languages_list') && function_exists('pll_default_language')) {
            $def = pll_default_language();
            foreach ((array) pll_languages_list(['fields' => 'slug']) as $lng) {
                if ($lng === $def) {
                    continue;
                }
                $slugZ  = CHROME_OPTIONS_SLUG . '-' . $lng;
                $labelZ = $lng === 'zh' ? '中文' : strtoupper($lng);
                acf_add_options_page([
                    'page_title' => 'Header & Footer (' . $labelZ . ')',
                    'menu_title' => 'Header & Footer (' . $labelZ . ')',
                    'menu_slug'  => $slugZ,
                    'icon_url'   => 'dashicons-translation',
                    'position'   => 59,
                    'capability' => 'edit_theme_options',
                    'redirect'   => false,
                    'post_id'    => 'options_' . $lng,
                ]);
                $chromeLoc[] = [['param' => 'options_page', 'operator' => '==', 'value' => $slugZ]];
            }
        }

        // Structured Header group: logo + a real menu repeater (with submenus,
        // rendered back into the original <ul> template) + search.
        $pl = fn (string $key, string $name, string $label, array $extra = []) => array_merge([
            'key' => $key, 'name' => $name, 'label' => $label, 'type' => 'page_link',
            'post_type' => ['page'], 'allow_null' => 1, 'allow_archives' => 0, 'multiple' => 0,
        ], $extra);
        acf_add_local_field_group([
            'key'   => 'group_sanyuan_header',
            'title' => 'Header (Logo + Menu) — site-wide',
            'fields' => [
                ['key' => 'field_tab_h_logo', 'label' => section_tab_label(1), 'type' => 'tab', 'placement' => 'top'],
                ['key' => 'field_h_logo', 'name' => 'header_logo', 'label' => 'Main logo',
                 'type' => 'image', 'return_format' => 'url', 'preview_size' => 'thumbnail',
                 ],
                ['key' => 'field_h_logo_white', 'name' => 'header_logo_white', 'label' => 'Sticky header logo (white)',
                 'type' => 'image', 'return_format' => 'url', 'preview_size' => 'thumbnail',
                 ],

                ['key' => 'field_tab_h_menu', 'label' => section_tab_label(2), 'type' => 'tab', 'placement' => 'top'],
                ['key' => 'field_h_menu', 'name' => 'header_menu', 'label' => 'Main menu',
                 'type' => 'repeater', 'layout' => 'block', 'button_label' => 'Add menu item',
                 'instructions' => 'Drag to reorder. Each item can have a submenu.',
                 'sub_fields' => [
                    ['key' => 'field_h_m_label', 'name' => 'label', 'label' => 'Label', 'type' => 'text', 'wrapper' => ['width' => '40']],
                    $pl('field_h_m_link', 'link', 'Link', ['wrapper' => ['width' => '60']]),
                    ['key' => 'field_h_m_children', 'name' => 'children', 'label' => 'Submenu',
                     'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add child item',
                     'sub_fields' => [
                        ['key' => 'field_h_c_label', 'name' => 'label', 'label' => 'Label', 'type' => 'text'],
                        $pl('field_h_c_link', 'link', 'Link'),
                     ]],
                 ]],

                ['key' => 'field_tab_h_search', 'label' => section_tab_label(3), 'type' => 'tab', 'placement' => 'top'],
                ['key' => 'field_h_search_show', 'name' => 'header_search_show', 'label' => 'Show search box',
                 'type' => 'true_false', 'ui' => 1, 'default_value' => 1],
                ['key' => 'field_h_search_ph', 'name' => 'header_search_placeholder', 'label' => 'Placeholder',
                 'type' => 'text', 'placeholder' => 'Find Products Here',
                 ],
            ],
            'location' => $chromeLoc,
            'menu_order' => 0, 'style' => 'default',
        ]);

        // Structured Footer group: product links + company info + socials +
        // copyright, rendered back into the original footer template.
        acf_add_local_field_group([
            'key'   => 'group_sanyuan_footer',
            'title' => 'Footer — site-wide',
            'fields' => [
                ['key' => 'field_tab_f_company', 'label' => section_tab_label(1), 'type' => 'tab', 'placement' => 'top'],
                ['key' => 'field_f_company', 'name' => 'footer_company', 'label' => 'Company name', 'type' => 'text', 'placeholder' => 'Sanyuan Cable'],
                ['key' => 'field_f_address', 'name' => 'footer_address', 'label' => 'Address', 'type' => 'textarea', 'rows' => 2, 'placeholder' => "No.6 Guifang Road…"],
                ['key' => 'field_f_email', 'name' => 'footer_email', 'label' => 'Email', 'type' => 'text', 'placeholder' => 'info@sanyuancable.com.cn'],

                ['key' => 'field_tab_f_products', 'label' => section_tab_label(2), 'type' => 'tab', 'placement' => 'top'],
                ['key' => 'field_f_products', 'name' => 'footer_products', 'label' => 'Product links',
                 'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add link',
                 'instructions' => 'Drag to reorder.',
                 'sub_fields' => [
                    ['key' => 'field_f_p_label', 'name' => 'label', 'label' => 'Label', 'type' => 'text', 'wrapper' => ['width' => '50']],
                    ['key' => 'field_f_p_link', 'name' => 'link', 'label' => 'Link', 'type' => 'text', 'wrapper' => ['width' => '50'], 'placeholder' => 'product_list/28.html'],
                 ]],

                ['key' => 'field_tab_f_regions', 'label' => section_tab_label(3), 'type' => 'tab', 'placement' => 'top'],
                ['key' => 'field_f_regions', 'name' => 'footer_regions', 'label' => 'Regions',
                 'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add region',
                 'sub_fields' => [
                    ['key' => 'field_f_r_name', 'name' => 'name', 'label' => 'Region name', 'type' => 'text', 'wrapper' => ['width' => '60']],
                    ['key' => 'field_f_r_link', 'name' => 'link', 'label' => 'Link', 'type' => 'text', 'wrapper' => ['width' => '40'], 'placeholder' => '/contact/'],
                 ]],

                ['key' => 'field_tab_f_social', 'label' => section_tab_label(4), 'type' => 'tab', 'placement' => 'top'],
                ['key' => 'field_f_socials', 'name' => 'footer_socials', 'label' => 'Social links',
                 'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add social link',
                 'sub_fields' => [
                    ['key' => 'field_f_s_title', 'name' => 'title', 'label' => 'Name', 'type' => 'text', 'wrapper' => ['width' => '25']],
                    ['key' => 'field_f_s_icon', 'name' => 'icon', 'label' => 'Icon', 'type' => 'image', 'return_format' => 'url', 'preview_size' => 'thumbnail', 'wrapper' => ['width' => '25']],
                    ['key' => 'field_f_s_url', 'name' => 'url', 'label' => 'Link', 'type' => 'url', 'wrapper' => ['width' => '50']],
                 ]],

                ['key' => 'field_tab_f_copyright', 'label' => section_tab_label(6), 'type' => 'tab', 'placement' => 'top'],
                ['key' => 'field_f_copyright', 'name' => 'footer_copyright', 'label' => 'Copyright line (HTML)',
                 'type' => 'textarea', 'rows' => 3, 'instructions' => 'Keep HTML tags; change text only.'],
            ],
            'location' => $chromeLoc,
            'menu_order' => 5, 'style' => 'default',
        ]);

        $chrome = chrome_fields_data();
        if ($chrome) {
            acf_add_local_field_group([
                'key'   => 'group_sanyuan_chrome',
                'title' => 'Floating contact bar — site-wide',
                'fields' => $buildFields($chrome, chrome_section_labels(), 'chrome'),
                'location' => $chromeLoc,
                'menu_order' => 10, 'style' => 'default',
            ]);
        }
    }
});
