<?php

/**
 * One-time setup for the SANYUAN theme — creates the WordPress Pages that the
 * theme renders dynamically (see mirror_pages() in app/routes.php), sets the
 * static front page, and enables pretty permalinks.
 *
 * Run once after activating the theme:
 *     wp eval-file wp-content/themes/sanyuan-theme/app/setup-pages.php
 * or:
 *     php -r 'define("WP_USE_THEMES",false); require "wp-load.php";
 *             require "wp-content/themes/sanyuan-theme/app/setup-pages.php";'
 */

if (! function_exists('wp_insert_post')) {
    return;
}

$pages = [
    'home'                       => 'Home',
    'about'                      => 'About',
    'product'                    => 'Product',
    'news'                       => 'News',
    'support'                    => 'Support',
    'contact'                    => 'Contact',
    'esg'                        => 'ESG',
    'cable-compliance'           => 'Cable Compliance',
    'cable-lab-overview'         => 'Cable Lab Overview',
    'cable-testing-inspection'   => 'Cable Testing & Inspection',
];

$ids = [];
foreach ($pages as $slug => $title) {
    $existing = get_page_by_path($slug);
    if ($existing) {
        $ids[$slug] = $existing->ID;
        continue;
    }
    $id = wp_insert_post([
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => '',
    ]);
    if (! is_wp_error($id)) {
        $ids[$slug] = $id;
    }
}

// Static front page = the "Home" Page.
if (! empty($ids['home'])) {
    update_option('show_on_front', 'page');
    update_option('page_on_front', $ids['home']);
}

// Pretty permalinks so /about/, /product/, … resolve to their Pages.
if (get_option('permalink_structure') !== '/%postname%/') {
    global $wp_rewrite;
    update_option('permalink_structure', '/%postname%/');
    if (isset($wp_rewrite)) {
        $wp_rewrite->set_permalink_structure('/%postname%/');
        $wp_rewrite->flush_rules(true);
    }
}

if (defined('WP_CLI') || PHP_SAPI === 'cli') {
    echo "SANYUAN pages ready: " . implode(', ', array_keys($ids)) . "\n";
}
