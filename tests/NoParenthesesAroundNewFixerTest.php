<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Tests;

use PhpCsFixer\Fixer\FixerInterface;
use PhpSoftBox\CsFixer\Fixers\NoParenthesesAroundNewFixer;
use PhpSoftBox\CsFixer\Tests\Support\FixerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(NoParenthesesAroundNewFixer::class)]
final class NoParenthesesAroundNewFixerTest extends FixerTestCase
{
    /**
     * Проверяет, что скобки вокруг new удаляются при цепочке вызова.
     */
    #[Test]
    public function testRemovesParenthesesWhenChained(): void
    {
        $expected = <<<'PHP'
<?php
$foo = new Foo()->bar();
PHP;
        $input = <<<'PHP'
<?php
$foo = (new Foo())->bar();
PHP;

        $this->doTest($expected, $input);
    }

    /**
     * Проверяет, что скобки вокруг new удаляются при nullsafe-цепочке.
     */
    #[Test]
    public function testRemovesParenthesesWhenNullsafeChained(): void
    {
        $expected = <<<'PHP'
<?php
$foo = new Foo()?->bar();
PHP;
        $input = <<<'PHP'
<?php
$foo = (new Foo())?->bar();
PHP;

        $this->doTest($expected, $input);
    }

    /**
     * Проверяет, что скобки сохраняются, если цепочки вызова нет.
     */
    #[Test]
    public function testLeavesParenthesesWhenNotChained(): void
    {
        $expected = <<<'PHP'
<?php
$foo = (new Foo());
PHP;

        $this->doTest($expected);
    }

    protected function createFixer(): FixerInterface
    {
        return new NoParenthesesAroundNewFixer();
    }
}
