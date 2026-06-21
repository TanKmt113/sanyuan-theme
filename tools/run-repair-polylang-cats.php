<?php

/**
 * Repair Polylang en↔zh pairs for mirrored WooCommerce product categories.
 *   php wp-content/themes/sanyuan-theme/tools/run-repair-polylang-cats.php
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

echo "Repairing Polylang product_cat translations...\n";
echo str_repeat('-', 60) . "\n";

$summary = \App\repair_polylang_product_categories(function (string $line) {
    echo $line . "\n";
});

echo str_repeat('-', 60) . "\n";
printf(
    "Done. repaired=%d zh_created=%d zh_renamed=%d merged=%d deleted=%d errors=%d\n",
    $summary['repaired'],
    $summary['zh_created'] ?? 0,
    $summary['zh_renamed'] ?? 0,
    $summary['merged'],
    $summary['deleted'],
    $summary['errors']
);
