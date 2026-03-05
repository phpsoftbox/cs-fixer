<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer;

use PhpCsFixer\Fixer\FixerInterface;
use PhpSoftBox\CsFixer\Fixers\BlankLineAfterNewInstantiationFixer;
use PhpSoftBox\CsFixer\Fixers\ConstructorParamsMultilineFixer;
use PhpSoftBox\CsFixer\Fixers\EchoTagHtmlEscapeFixer;
use PhpSoftBox\CsFixer\Fixers\NoParenthesesAroundNewFixer;

final class FixerProvider
{
    /**
     * @return list<FixerInterface>
     */
    public static function getFixers(): array
    {
        return [
            new BlankLineAfterNewInstantiationFixer(),
            new ConstructorParamsMultilineFixer(),
            new EchoTagHtmlEscapeFixer(),
            new NoParenthesesAroundNewFixer(),
        ];
    }
}
