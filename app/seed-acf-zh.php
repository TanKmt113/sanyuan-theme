<?php

/**
 * Seed all Chinese (zh) content: options, home CTAs, about hero, page titles,
 * and the News & Events category translation.
 *
 * Run once:
 *   php -r 'define("WP_USE_THEMES",false); require "wp-load.php";
 *           require "wp-content/themes/sanyuan-theme/app/seed-acf-zh.php";'
 */

if (! function_exists('update_field')) {
    return;
}

define('SANYUAN_SEED_INCLUDE_ONLY', true);
require_once __DIR__ . '/seed-options-zh.php';
require_once __DIR__ . '/seed-acf.php';
require_once __DIR__ . '/seed-mirror-zh-acf.php';

/** Chinese admin titles for managed WP pages. */
function seed_zh_page_titles(): array
{
    return [
        'home'                     => '首页',
        'about'                    => '关于我们',
        'product'                  => '产品中心',
        'news'                     => '新闻与活动',
        'support'                  => '服务支持',
        'contact'                  => '联系我们',
        'esg'                      => 'ESG',
        'cable-compliance'         => '电缆合规',
        'cable-lab-overview'       => '电缆实验室概览',
        'cable-testing-inspection' => '电缆测试与检验',
    ];
}

/** Align zh page slugs with EN (Polylang allows duplicate slugs per language). */
function seed_zh_fix_page_slugs(): array
{
    global $wpdb;
    $fixed = [];
    foreach (array_keys(seed_zh_page_titles()) as $slug) {
        $en = get_page_by_path($slug);
        if (! $en || ! function_exists('pll_get_post')) {
            continue;
        }
        $zhId = (int) (pll_get_post($en->ID, 'zh') ?: 0);
        if ($zhId <= 0) {
            continue;
        }
        $cur = get_post_field('post_name', $zhId);
        if ($cur !== $slug) {
            $wpdb->update($wpdb->posts, ['post_name' => $slug], ['ID' => $zhId]);
            clean_post_cache($zhId);
            $fixed[] = "$cur=>$slug";
        }
    }

    return $fixed;
}

/** Update zh page post_title values in wp-admin. */
function seed_zh_update_page_titles(): array
{
    $updated = [];
    foreach (seed_zh_page_titles() as $slug => $title) {
        $en = get_page_by_path($slug);
        if (! $en || ! function_exists('pll_get_post')) {
            continue;
        }
        $zhId = (int) (pll_get_post($en->ID, 'zh') ?: 0);
        if ($zhId <= 0) {
            continue;
        }
        if (get_the_title($zhId) !== $title) {
            wp_update_post(['ID' => $zhId, 'post_title' => $title]);
            $updated[] = "$slug:$title";
        }
    }

    return $updated;
}

/** Ensure a zh translation exists for the News & Events category. */
function seed_zh_news_category(): ?string
{
    if (! function_exists('pll_get_term') || ! function_exists('pll_set_term_language')) {
        return null;
    }
    $prev = function_exists('PLL') ? PLL()->curlang : null;
    if (function_exists('PLL') && function_exists('default_lang')) {
        PLL()->curlang = PLL()->model->get_language(default_lang());
    }
    $en = get_term_by('slug', App\SANYUAN_NEWS_CAT, 'category');
    if ($prev !== null) {
        PLL()->curlang = $prev;
    }
    if (! $en) {
        return null;
    }
    $enId = (int) $en->term_id;
    $zhId = (int) (pll_get_term($enId, 'zh') ?: 0);
    if ($zhId <= 0) {
        $created = wp_insert_term('新闻与活动', 'category', ['slug' => App\SANYUAN_NEWS_CAT]);
        if (is_wp_error($created)) {
            return 'news-cat-error:' . $created->get_error_message();
        }
        $zhId = (int) $created['term_id'];
        pll_set_term_language($zhId, 'zh');
        pll_save_term_translations(['en' => $enId, 'zh' => $zhId]);
    }
    global $wpdb;
    $wpdb->update($wpdb->terms, ['slug' => App\SANYUAN_NEWS_CAT], ['term_id' => $zhId]);
    clean_term_cache($zhId, 'category');

    return "news-cat-zh#$zhId";
}

/** Seed About hero fields on the zh page (from cn.sanyuancable.com.cn mirror). */
function seed_zh_about_hero(): array
{
    $about = get_page_by_path('about');
    if (! $about || ! function_exists('pll_get_post')) {
        return [];
    }
    $zhId = (int) (pll_get_post($about->ID, 'zh') ?: 0);
    if ($zhId <= 0) {
        return [];
    }
    $enImg = get_field('about_hero_image', $about->ID);
    $imgId = is_numeric($enImg) ? (int) $enImg : 0;
    if ($imgId <= 0 && is_string($enImg) && $enImg !== '') {
        $imgId = (int) attachment_url_to_postid($enImg);
    }
    if ($imgId <= 0) {
        $imgId = sanyuan_seed_attach_theme_image('7c7d81ee-69d7-4e0f-929c-750b92257d3f_13e34b.jpeg');
    }
    $fields = [
        'about_hero_title'     => '领先线缆制造商',
        'about_hero_highlight' => '三十载',
        'about_hero_title_end' => '卓越品质',
        'about_hero_desc'      => '杭州三元电缆有限公司成立于 1995 年，最初是一家专业的同轴线缆制造商。如今，三元已发展成为一家集通信线缆、BMS 线缆及工业线缆于一体的综合性制造商。',
    ];
    $seeded = [];
    foreach ($fields as $name => $value) {
        if (sanyuan_seed_field_repair($name, $value, $zhId, 'field_' . $name)) {
            $seeded[] = "about-zh:$name";
        }
    }
    if ($imgId > 0 && sanyuan_seed_media_field('about_hero_image', $imgId, $zhId, 'field_about_hero_image')) {
        $seeded[] = 'about-zh:about_hero_image';
    }

    return $seeded;
}

$log   = array_merge(seed_zh_fix_page_slugs(), seed_zh_update_page_titles());
$cat   = seed_zh_news_category();
$log[] = 'options_zh seeded';
seed_options_zh_run();
require_once __DIR__ . '/seed-home-zh.php';
if ($cat) {
    $log[] = $cat;
}
$log = array_merge($log, seed_zh_about_hero());
$mirror = seed_zh_all_mirror_acf(false);
$log[]  = 'mirror-acf: ' . count($mirror) . ' fields';

if (defined('WP_CLI') || PHP_SAPI === 'cli') {
    echo "seed-acf-zh complete:\n  " . implode("\n  ", $log) . "\n";
}
