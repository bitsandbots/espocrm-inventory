<?php

// Load EspoCRM vendor autoloader — required for Espo\Core\* namespace resolution.
// ESPO_PATH defaults to the sibling espocrm directory but can be overridden.
$espoPath = rtrim(getenv('ESPO_PATH') ?: dirname(__DIR__) . '/../espocrm', '/');
$vendorAutoload = $espoPath . '/vendor/autoload.php';

if (!file_exists($vendorAutoload)) {
    fwrite(STDERR, "ERROR: EspoCRM vendor/autoload.php not found at {$vendorAutoload}\n");
    fwrite(STDERR, "Set ESPO_PATH=/path/to/espocrm or place espocrm at " . dirname($espoPath) . "/espocrm\n");
    exit(1);
}

$loader = require $vendorAutoload;

// Add Inventory module and test namespaces.
$moduleRoot = dirname(__DIR__) . '/custom/Espo/Modules/Inventory';
$testRoot = __DIR__ . '/unit/Espo/Modules/Inventory';

$loader->addPsr4('Espo\\Modules\\Inventory\\', $moduleRoot . '/');
$loader->addPsr4('tests\\unit\\Espo\\Modules\\Inventory\\', $testRoot . '/');
$loader->addPsr4('tests\\unit\\Espo\\', dirname($testRoot, 2) . '/');
