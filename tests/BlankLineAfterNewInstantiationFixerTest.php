<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Tests;

use PhpCsFixer\Fixer\FixerInterface;
use PhpSoftBox\CsFixer\Fixers\BlankLineAfterNewInstantiationFixer;
use PhpSoftBox\CsFixer\Tests\Support\FixerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(BlankLineAfterNewInstantiationFixer::class)]
final class BlankLineAfterNewInstantiationFixerTest extends FixerTestCase
{
    /**
     * Проверяет, что после присваивания new добавляется пустая строка, если переменная используется сразу.
     */
    #[Test]
    public function testInsertsBlankLineWhenVariableUsedImmediately(): void
    {
        $expected = <<<'PHP'
<?php
$foo = new Foo();

$foo->bar();
PHP;
        $input = <<<'PHP'
<?php
$foo = new Foo();
$foo->bar();
PHP;

        $this->doTest($expected, $input);
    }

    /**
     * Проверяет, что после return new пустая строка не добавляется.
     */
    #[Test]
    public function testDoesNotInsertAfterReturnNew(): void
    {
        $expected = <<<'PHP'
<?php
return new Foo();
PHP;

        $this->doTest($expected);
    }

    /**
     * Проверяет, что пустая строка не добавляется, если следующая команда не использует переменную.
     */
    #[Test]
    public function testDoesNotInsertWhenVariableNotUsed(): void
    {
        $expected = <<<'PHP'
<?php
$foo = new Foo();
$bar->baz();
PHP;

        $this->doTest($expected);
    }

    /**
     * Проверяет, что пустая строка не вставляется перед закрывающей фигурной скобкой.
     */
    #[Test]
    public function testSkipsBeforeBlockClose(): void
    {
        $expected = <<<'PHP'
<?php
if ($flag) {
    $foo = new Foo();
}
PHP;

        $this->doTest($expected);
    }

    protected function createFixer(): FixerInterface
    {
        return new BlankLineAfterNewInstantiationFixer();
    }
}
