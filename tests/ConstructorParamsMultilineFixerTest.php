<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Tests;

use PhpCsFixer\Fixer\FixerInterface;
use PhpSoftBox\CsFixer\Fixers\ConstructorParamsMultilineFixer;
use PhpSoftBox\CsFixer\Tests\Support\FixerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ConstructorParamsMultilineFixer::class)]
final class ConstructorParamsMultilineFixerTest extends FixerTestCase
{
    /**
     * Проверяет, что параметры конструктора с продвигаемыми свойствами становятся многострочными.
     */
    #[Test]
    public function testPromotedParamsBecomeMultiline(): void
    {
        $expected = <<<'PHP'
<?php
class A {
    public function __construct(
        private Foo $foo,
        Bar $bar
    ) {}
}
PHP;
        $input = <<<'PHP'
<?php
class A { public function __construct(private Foo $foo, Bar $bar) {} }
PHP;

        $this->doTest($expected, $input);
    }

    /**
     * Проверяет, что конструктор без модификаторов видимости не изменяется.
     */
    #[Test]
    public function testSkipsConstructorWithoutPromotedProperties(): void
    {
        $expected = <<<'PHP'
<?php
class A { public function __construct(Foo $foo, Bar $bar) {} }
PHP;

        $this->doTest($expected);
    }

    /**
     * Проверяет, что уже многострочный список параметров остаётся без изменений.
     */
    #[Test]
    public function testSkipsAlreadyMultilineConstructor(): void
    {
        $expected = <<<'PHP'
<?php
class A {
    public function __construct(
        private Foo $foo,
        Bar $bar
    ) {}
}
PHP;

        $this->doTest($expected);
    }

    protected function createFixer(): FixerInterface
    {
        return new ConstructorParamsMultilineFixer();
    }
}
