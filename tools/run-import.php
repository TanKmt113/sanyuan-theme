<?php

/**
 * CLI runner for the product importer (wp-cli is not installed on this host).
 *
 *   php wp-content/themes/sanyuan-theme/tools/run-import.php            # all
 *   php wp-content/themes/sanyuan-theme/tools/run-import.php 29 100 5   # subset
 *
 * Bootstraps WordPress, loads the importer, runs it, prints a summary.
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

require __DIR__ . '/../app/import-products.php';

$ids = array_slice($argv, 1);

echo $ids ? ('Importing ' . count($ids) . " product(s): " . implode(', ', $ids) . "\n")
          : "Importing ALL product mirror files...\n";
echo str_repeat('-', 60) . "\n";

$summary = \App\sanyuan_import_products($ids, function (string $line) {
    echo $line . "\n";
});

echo str_repeat('-', 60) . "\n";
printf(
    "Done. total=%d created=%d updated=%d errors=%d\n",
    $summary['total'], $summary['created'], $summary['updated'], $summary['errors']
);
