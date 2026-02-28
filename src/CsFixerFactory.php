<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer;

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

final class CsFixerFactory
{
    private Config $config;

    private function __construct(Config $config)
    {
        $this->config = $config;
    }

    public static function create(): self
    {
        $config = new Config();

        $config->setRiskyAllowed(true);
        $config->registerCustomFixers(FixerProvider::getFixers());
        $config->setRules(self::defaultRules());

        return new self($config);
    }

    public function setFinder(Finder $finder): self
    {
        $this->config->setFinder($finder);

        return $this;
    }

    public function setCacheFile(string $path): self
    {
        $this->config->setCacheFile($path);

        return $this;
    }

    public function build(): Config
    {
        return $this->config;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultRules(): array
    {
        return [
            '@PSR12'                  => true,
            'array_syntax'            => ['syntax' => 'short'],
            'ordered_imports'         => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
            'no_unused_imports'       => true,
            'global_namespace_import' => [
                'import_classes'   => true,
                'import_constants' => true,
                'import_functions' => true,
            ],
            'fully_qualified_strict_types' => true,
            'native_function_invocation'   => [
                'include' => ['@all'],
                'scope'   => 'namespaced',
                'strict'  => true,
            ],
            'native_constant_invocation' => [
                'scope'  => 'namespaced',
                'strict' => true,
            ],
            'single_quote'                     => true,
            'trailing_comma_in_multiline'      => ['elements' => ['arrays', 'arguments', 'parameters']],
            'concat_space'                     => ['spacing' => 'one'],
            'blank_line_between_import_groups' => true,
            'blank_line_after_opening_tag'     => true,
            'blank_line_before_statement'      => [
                'statements' => ['return', 'throw'],
            ],
            'declare_equal_normalize'    => ['space' => 'none'],
            'phpdoc_align'               => ['align' => 'left'],
            'phpdoc_scalar'              => true,
            'phpdoc_trim'                => true,
            'no_superfluous_phpdoc_tags' => [
                'allow_mixed'         => true,
                'allow_unused_params' => false,
            ],
            'binary_operator_spaces' => [
                'default'   => 'single_space',
                'operators' => [
                    '='  => 'align_single_space_minimal',
                    '=>' => 'align_single_space_minimal',
                ],
            ],
            'yoda_style' => false,

            // Custom rules
            'PhpSoftBox/blank_line_after_new_instantiation' => true,
            'PhpSoftBox/constructor_params_multiline'       => true,
            'PhpSoftBox/no_parentheses_around_new'          => true,
        ];
    }
}
