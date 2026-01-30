<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Console;

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Fixer\FixerInterface;
use PhpSoftBox\CsFixer\CsFixerFactory;
use PhpSoftBox\CsFixer\FixerProvider;

use function getcwd;

abstract class AbstractCsFixerHandler
{
    abstract protected function getFinder(): Finder;

    /**
     * @param list<FixerInterface> $fixers
     * @return list<FixerInterface>
     */
    protected function extendFixers(array $fixers): array
    {
        return $fixers;
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    protected function extendRules(array $rules): array
    {
        return $rules;
    }

    protected function cacheFile(): ?string
    {
        return getcwd() . '/.php-cs-fixer.cache';
    }

    public function createConfig(): Config
    {
        $config = new Config();

        $config->setRiskyAllowed(true);
        $config->registerCustomFixers($this->extendFixers(FixerProvider::getFixers()));
        $config->setRules($this->extendRules(CsFixerFactory::defaultRules()));
        $config->setFinder($this->getFinder());

        $cacheFile = $this->cacheFile();
        if ($cacheFile !== null && $cacheFile !== '') {
            $config->setCacheFile($cacheFile);
        }

        return $config;
    }
}
