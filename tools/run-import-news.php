<?php

/**
 * CLI runner for the News importer (wp-cli is not installed on this host).
 *
 *   php wp-content/themes/sanyuan-theme/tools/run-import-news.php
 *
 * Bootstraps WordPress, loads the importer, runs it, flushes rewrite rules so
 * the new /news-detail/<slug>/ permalinks resolve, prints a summary.
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}

// Walk up to wp-load.php from the theme directory.
$dir = __DIR__;
while ($dir !== '/' && ! file_exists($dir . '/wp-load.php')) {
    $dir = dirname($dir);
}
if (! file_exists($dir . '/wp-load.php')) {
    exit("wp-load.php not found\n");
}

define('WP_USE_THEMES', false);
require $dir . '/wp-load.php';

require __DIR__ . '/../app/import-news.php';

echo "Importing News & Events from the mirror...\n";
echo str_repeat('-', 60) . "\n";

$summary = \App\sanyuan_import_news(function (string $line) {
    echo $line . "\n";
});

echo str_repeat('-', 60) . "\n";
printf(
    "Done. total=%d created=%d updated=%d errors=%d\n",
    $summary['total'], $summary['created'], $summary['updated'], $summary['errors']
);

flush_rewrite_rules(false);
echo "Rewrite rules flushed.\n";
