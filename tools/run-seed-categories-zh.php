<?php

/**
 * CLI runner: create Polylang zh translations for mirrored product_cat terms.
 *   php wp-content/themes/sanyuan-theme/tools/run-seed-categories-zh.php
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}

$dir = __DIR__;
while ($dir !== '/' && ! file_exists($dir . '/wp-load.php')) {
    $dir = dirname($dir);
}
if (! file_exists($dir . '/wp-load.php')) {
    exit("wp-load.php not found\n");
}

define('WP_USE_THEMES', false);
require $dir . '/wp-load.php';
require __DIR__ . '/../app/import-categories.php';

echo "Seeding zh product_cat translations...\n";
echo str_repeat('-', 60) . "\n";

$summary = \App\seed_zh_product_categories(function (string $line) {
    echo $line . "\n";
});

echo str_repeat('-', 60) . "\n";
printf(
    "Done. created=%d updated=%d skipped=%d errors=%d\n",
    $summary['created'],
    $summary['updated'],
    $summary['skipped'],
    $summary['errors']
);
