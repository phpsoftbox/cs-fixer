<?php

require __DIR__ . '/vendor/autoload.php';

use PhpSoftBox\CsFixer\CsFixerFactory;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->exclude('vendor')
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/bin')
    ->in(__DIR__ . '/tests')
    ->ignoreVCS(true)
    ->name('*.php');

return CsFixerFactory::create()
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->build();
