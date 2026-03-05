<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Fixers;

use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

use function in_array;
use function strtolower;

use const T_CLOSE_TAG;
use const T_ECHO;
use const T_OPEN_TAG_WITH_ECHO;
use const T_STRING;
use const T_VARIABLE;

final class EchoTagHtmlEscapeFixer implements FixerInterface
{
    public function getName(): string
    {
        return 'PhpSoftBox/echo_tag_html_escape';
    }

    public function getDefinition(): FixerDefinition
    {
        return new FixerDefinition(
            'Wrap unsafe phtml echo output into html() helper. raw() is allowed as an explicit trusted output.',
            [
                new CodeSample(
                    <<<'PHP'
<?php
?>
<h1><?= $title ?></h1>
<?php echo $subtitle; ?>
PHP
                ),
            ],
        );
    }

    public function isRisky(): bool
    {
        return true;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_OPEN_TAG_WITH_ECHO) || $tokens->isTokenKindFound(T_ECHO);
    }

    public function supports(SplFileInfo $file): bool
    {
        return strtolower((string) $file->getExtension()) === 'phtml';
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        $count = $tokens->count();
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->isGivenKind(T_OPEN_TAG_WITH_ECHO)) {
                $this->fixShortEchoTag($tokens, $i);
                $count = $tokens->count();

                continue;
            }

            if ($tokens[$i]->isGivenKind(T_ECHO)) {
                $this->fixEchoStatement($tokens, $i);
                $count = $tokens->count();
            }
        }
    }

    public function getPriority(): int
    {
        return 0;
    }

    private function fixShortEchoTag(Tokens $tokens, int $echoTagIndex): void
    {
        $closeTagIndex = $tokens->getNextTokenOfKind($echoTagIndex, [[T_CLOSE_TAG]]);
        if ($closeTagIndex === null) {
            return;
        }

        $this->wrapUnsafeExpression($tokens, $echoTagIndex + 1, $closeTagIndex - 1);
    }

    private function fixEchoStatement(Tokens $tokens, int $echoIndex): void
    {
        $statementEndIndex = $this->findEchoStatementEnd($tokens, $echoIndex);
        if ($statementEndIndex === null) {
            return;
        }

        $expressionEndIndex = $statementEndIndex - 1;
        $this->wrapUnsafeExpression($tokens, $echoIndex + 1, $expressionEndIndex);
    }

    private function findEchoStatementEnd(Tokens $tokens, int $echoIndex): ?int
    {
        $count = $tokens->count();
        for ($i = $echoIndex + 1; $i < $count; $i++) {
            if ($tokens[$i]->equals(';') || $tokens[$i]->isGivenKind(T_CLOSE_TAG)) {
                return $i;
            }
        }

        return null;
    }

    private function wrapUnsafeExpression(Tokens $tokens, int $fromIndex, int $toIndex): void
    {
        if ($fromIndex > $toIndex) {
            return;
        }

        $firstMeaningful = $tokens->getNextMeaningfulToken($fromIndex - 1);
        if ($firstMeaningful === null || $firstMeaningful > $toIndex) {
            return;
        }

        $lastMeaningful = $tokens->getPrevMeaningfulToken($toIndex + 1);
        if ($lastMeaningful === null || $lastMeaningful < $firstMeaningful) {
            return;
        }

        if (
            $this->containsAllowedEscaperCall($tokens, $firstMeaningful, $lastMeaningful)
            || $this->isTrustedRawVariable($tokens, $firstMeaningful, $lastMeaningful)
        ) {
            return;
        }

        $tokens->insertAt($firstMeaningful, [
            new Token([T_STRING, 'html']),
            new Token('('),
        ]);
        $lastMeaningful += 2;
        $tokens->insertAt($lastMeaningful + 1, [new Token(')')]);
    }

    private function containsAllowedEscaperCall(Tokens $tokens, int $fromIndex, int $toIndex): bool
    {
        for ($i = $fromIndex; $i <= $toIndex; $i++) {
            if (!$tokens[$i]->isGivenKind(T_STRING)) {
                continue;
            }

            $function = strtolower($tokens[$i]->getContent());
            if (!in_array($function, ['html', 'raw'], true)) {
                continue;
            }

            $nextMeaningful = $tokens->getNextMeaningfulToken($i);
            if ($nextMeaningful === null || $nextMeaningful > $toIndex) {
                continue;
            }

            if ($tokens[$nextMeaningful]->equals('(')) {
                return true;
            }
        }

        return false;
    }

    private function isTrustedRawVariable(Tokens $tokens, int $fromIndex, int $toIndex): bool
    {
        if ($fromIndex !== $toIndex) {
            return false;
        }

        if (!$tokens[$fromIndex]->isGivenKind(T_VARIABLE)) {
            return false;
        }

        return in_array($tokens[$fromIndex]->getContent(), ['$content', '$unsubscribeBlock'], true);
    }
}
