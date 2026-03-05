<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Tests;

use PhpCsFixer\Fixer\FixerInterface;
use PhpSoftBox\CsFixer\Fixers\EchoTagHtmlEscapeFixer;
use PhpSoftBox\CsFixer\Tests\Support\FixerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(EchoTagHtmlEscapeFixer::class)]
final class EchoTagHtmlEscapeFixerTest extends FixerTestCase
{
    #[Test]
    public function testWrapsUnsafeShortEchoTagWithHtmlHelper(): void
    {
        $expected = <<<'PHP'
<?php
?>
<p><?= html($title) ?></p>
PHP;
        $input = <<<'PHP'
<?php
?>
<p><?= $title ?></p>
PHP;

        $this->doTest($expected, $input);
    }

    #[Test]
    public function testKeepsShortEchoWhenAlreadyEscaped(): void
    {
        $expected = <<<'PHP'
<?php
?>
<p><?= html($title) ?></p>
PHP;

        $this->doTest($expected);
    }

    #[Test]
    public function testKeepsShortEchoWhenEscapingIsNested(): void
    {
        $expected = <<<'PHP'
<?php
?>
<div><?= nl2br(html($body), false) ?></div>
PHP;

        $this->doTest($expected);
    }

    #[Test]
    public function testKeepsShortEchoWhenRawHelperUsedExplicitly(): void
    {
        $expected = <<<'PHP'
<?php
?>
<div><?= raw($content) ?></div>
PHP;

        $this->doTest($expected);
    }

    #[Test]
    public function testKeepsTrustedRawVariablesForLayout(): void
    {
        $expected = <<<'PHP'
<?php
?>
<div><?= $content ?></div>
<footer><?= $unsubscribeBlock ?></footer>
PHP;

        $this->doTest($expected);
    }

    #[Test]
    public function testWrapsUnsafeEchoStatementWithHtmlHelper(): void
    {
        $expected = <<<'PHP'
<?php
echo html($title);
PHP;
        $input = <<<'PHP'
<?php
echo $title;
PHP;

        $this->doTest($expected, $input);
    }

    protected function createFixer(): FixerInterface
    {
        return new EchoTagHtmlEscapeFixer();
    }

    protected function fileName(): string
    {
        return 'test.phtml';
    }
}
