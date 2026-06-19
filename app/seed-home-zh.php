<?php

/**
 * Seed Chinese Home page ACF: hero overlay slogan + body CTA buttons.
 *
 * Run once:
 *   php -r 'define("WP_USE_THEMES",false); require "wp-load.php";
 *           require "wp-content/themes/sanyuan-theme/app/seed-home-zh.php";'
 */

if (! function_exists('update_field')) {
    return;
}

if (! defined('SANYUAN_SEED_INCLUDE_ONLY')) {
    define('SANYUAN_SEED_INCLUDE_ONLY', true);
}
require_once __DIR__ . '/seed-options-zh.php'; // seed_zh_page_url() only.

$home = get_page_by_path('home');
if (! $home) {
    if (defined('WP_CLI') || PHP_SAPI === 'cli') {
        echo "Home page not found.\n";
    }
    return;
}

$zhId = function_exists('pll_get_post') ? (int) (pll_get_post($home->ID, 'zh') ?: 0) : 0;
if ($zhId <= 0) {
    if (defined('WP_CLI') || PHP_SAPI === 'cli') {
        echo "Chinese Home translation not found.\n";
    }
    return;
}

$fields = [
    'home_hero_tagline'       => '成为全球客户最值得信赖的通信产品与解决方案提供商',
    'home_cta_partner_label'  => '查看全部产品',
    'home_cta_partner_link'   => seed_zh_page_url('product'),
    'home_cta_lab_label'      => '查看电缆实验室',
    'home_cta_lab_link'       => seed_zh_page_url('cable-lab-overview'),
    'home_cta_esg_label'      => '查看ESG管理',
    'home_cta_esg_link'       => seed_zh_page_url('esg'),
    'home_cta_docs_label'     => '查看文档',
    'home_cta_docs_link'      => seed_zh_page_url('support'),
];

foreach ($fields as $name => $value) {
    update_field($name, $value, $zhId);
}

if (defined('WP_CLI') || PHP_SAPI === 'cli') {
    echo "Home zh (#$zhId) seeded: " . implode(', ', array_keys($fields)) . "\n";
}
