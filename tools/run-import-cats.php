<?php

/**
 * CLI runner for the category importer.
 *   php wp-content/themes/sanyuan-theme/tools/run-import-cats.php
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

echo "Importing category tree from product.html...\n";
echo str_repeat('-', 60) . "\n";

$summary = \App\sanyuan_import_categories(function (string $line) {
    echo $line . "\n";
});

echo str_repeat('-', 60) . "\n";
// Flush so the new term-archive rewrite rules are registered.
flush_rewrite_rules(true);
echo "Done + rewrite rules flushed.\n";
