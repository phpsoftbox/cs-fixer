<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Tests\Support;

use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

abstract class FixerTestCase extends TestCase
{
    abstract protected function createFixer(): FixerInterface;

    protected function doTest(string $expected, ?string $input = null): void
    {
        $fixer = $this->createFixer();
        $code  = $input ?? $expected;

        $tokens = Tokens::fromCode($code);
        $fixer->fix(new SplFileInfo('test.php'), $tokens);

        self::assertSame($expected, $tokens->generateCode());
    }
}
