<?php

/**
 * Download Chinese mirror HTML from cn.sanyuancable.com.cn into public/site/zh/.
 *
 * Run (needs network + write access to public/site/zh/):
 *   php -r 'define("WP_USE_THEMES",false); require "wp-load.php";
 *           require "wp-content/themes/sanyuan-theme/app/sync-zh-mirrors.php";'
 */

namespace App;

/** Managed mirror files (same names as sanyuan_page_files()). */
function sanyuan_zh_mirror_files(): array
{
    return [
        'index.html',
        'about.html',
        'about-acf.html',
        'product.html',
        'news.html',
        'Support.html',
        'concact.html',
        'ESG.html',
        'CableCompliance.html',
        'CableLabOverview.html',
        'CableTestingInspection.html',
    ];
}

/** Fetch one CN page and save under public/site/zh/. */
function sanyuan_sync_zh_mirror_file(string $filename): bool
{
    $base = 'https://cn.sanyuancable.com.cn/';
    $url  = $base . ltrim($filename, '/');
    if ($filename === 'about-acf.html') {
        $url = $base . 'about.html';
    }

    $body = function_exists('wp_remote_get')
        ? wp_remote_retrieve_body(wp_remote_get($url, ['timeout' => 90, 'sslverify' => false]))
        : @file_get_contents($url);
    if (! is_string($body) || strlen($body) < 1000) {
        return false;
    }

    $dir = get_theme_file_path('public/site/zh');
    if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
        return false;
    }

    return file_put_contents($dir . '/' . $filename, $body) !== false;
}

/** Sync zh_CN language pack required by the 300.cn runtime on CN mirrors. */
function sanyuan_sync_zh_language_pack(): bool
{
    $dir = get_theme_file_path('public/site/npublic/commonjs/language');
    if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
        return false;
    }

    $base = 'https://cn.sanyuancable.com.cn/npublic/commonjs/language/';
    $ok   = true;
    foreach (['zh_CN.js', 'zh_CN.min.js'] as $file) {
        $body = function_exists('wp_remote_get')
            ? wp_remote_retrieve_body(wp_remote_get($base . $file, ['timeout' => 30, 'sslverify' => false]))
            : @file_get_contents($base . $file);
        if (! is_string($body) || strlen($body) < 100) {
            $ok = false;
            continue;
        }
        if (file_put_contents($dir . '/' . $file, $body) === false) {
            $ok = false;
        }
    }

    return $ok;
}

/** Sync all managed zh mirror pages; returns list of synced filenames. */
function sanyuan_sync_zh_mirrors_all(): array
{
    sanyuan_sync_zh_language_pack();

    $ok = [];
    foreach (sanyuan_zh_mirror_files() as $file) {
        if (sanyuan_sync_zh_mirror_file($file)) {
            $ok[] = $file;
        }
    }

    return $ok;
}

if (! defined('SANYUAN_SEED_INCLUDE_ONLY')) {
    $synced = sanyuan_sync_zh_mirrors_all();
    $msg    = $synced === []
        ? "No zh mirror files synced (check network or permissions).\n"
        : 'Synced ' . count($synced) . " zh mirror file(s):\n  " . implode("\n  ", $synced) . "\n";
    if (defined('WP_CLI') || PHP_SAPI === 'cli') {
        echo $msg;
    }
}
