<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Fixers;

use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

use function str_contains;
use function strrpos;
use function strtolower;
use function substr;

use const T_ABSTRACT;
use const T_FINAL;
use const T_FUNCTION;
use const T_OPEN_TAG;
use const T_PRIVATE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_STATIC;
use const T_STRING;
use const T_WHITESPACE;

final class ConstructorParamsMultilineFixer implements FixerInterface
{
    public function getName(): string
    {
        return 'PhpSoftBox/constructor_params_multiline';
    }

    public function getDefinition(): FixerDefinition
    {
        return new FixerDefinition(
            'Force constructor parameters to be formatted on multiple lines when present.',
            [
                new CodeSample(
                    <<<'PHP'
<?php
class A { public function __construct(private Foo $foo, Bar $bar) {} }
PHP
                ),
            ],
        );
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_FUNCTION);
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        $count = $tokens->count();
        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->isGivenKind(T_FUNCTION)) {
                continue;
            }

            $nameIndex = $tokens->getNextMeaningfulToken($i);
            if ($nameIndex === null) {
                continue;
            }
            $nameToken = $tokens[$nameIndex];
            if (!$nameToken->isGivenKind(T_STRING) || strtolower($nameToken->getContent()) !== '__construct') {
                continue;
            }

            $openParen = $tokens->getNextTokenOfKind($nameIndex, ['(']);
            if ($openParen === null) {
                continue;
            }
            $closeParen = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $openParen);

            // Условие применения: переносим только если среди параметров есть модификаторы видимости (продвигаемые свойства)
            $hasPromotedVisibility = false;
            for ($j = $openParen + 1; $j < $closeParen; $j++) {
                if ($tokens[$j]->isGivenKind([
                    T_PRIVATE,
                    T_PUBLIC,
                    T_PROTECTED,
                    CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PRIVATE,
                    CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PUBLIC,
                    CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PROTECTED,
                ])) {
                    $hasPromotedVisibility = true;
                    break;
                }
            }
            if (!$hasPromotedVisibility) {
                continue;
            }

            // Уже многострочно? — пропустим
            $hasNewline = false;
            for ($j = $openParen + 1; $j < $closeParen; $j++) {
                if ($tokens[$j]->isWhitespace() && str_contains($tokens[$j]->getContent(), "\n")) {
                    $hasNewline = true;
                    break;
                }
            }
            if ($hasNewline) {
                continue;
            }

            $signatureStart = $this->findSignatureStart($tokens, $i);
            $classOpenIndex = $this->findClassOpenBrace($tokens, $signatureStart);
            $indent         = $this->detectIndent($tokens, $signatureStart);
            $classIndent    = '';
            if ($classOpenIndex !== null) {
                $classIndent = $this->detectIndent($tokens, $classOpenIndex);
                if ($indent === '') {
                    $indent = $classIndent . '    ';
                }
            }
            $paramIndent = $indent . '    ';

            // Перенос после '('
            $this->ensureWhitespace($tokens, $openParen + 1, "\n" . $paramIndent);

            // Перенос после каждой запятой внутри параметров
            $cursor = $openParen + 1;
            while ($cursor < $closeParen) {
                $comma = $tokens->getNextTokenOfKind($cursor, [',']);
                if ($comma === null || $comma >= $closeParen) {
                    break;
                }
                $this->ensureWhitespace($tokens, $comma + 1, "\n" . $paramIndent);
                $cursor     = $comma + 1;
                $closeParen = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $openParen);
            }

            // Перенос перед ')'
            $this->ensureWhitespaceBefore($tokens, $closeParen, "\n" . $indent);

            // Если метод стоит на одной строке с открывающей фигурной скобкой класса — переносим на новую строку
            if ($classOpenIndex !== null && !$this->hasNewlineBetween($tokens, $classOpenIndex, $signatureStart)) {
                $this->ensureWhitespaceBefore($tokens, $signatureStart, "\n" . $indent);
            }

            // Закрывающую скобку класса переносим на новую строку при необходимости
            if ($classOpenIndex !== null) {
                $classCloseIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $classOpenIndex);
                $prevMeaningful  = $tokens->getPrevMeaningfulToken($classCloseIndex);
                if ($prevMeaningful !== null && !$this->hasNewlineBetween($tokens, $prevMeaningful, $classCloseIndex)) {
                    $this->ensureWhitespaceBefore($tokens, $classCloseIndex, "\n" . $classIndent);
                }
            }

            // Обновим счётчик, т.к. меняли токены
            $count = $tokens->count();
        }
    }

    public function getPriority(): int
    {
        return 0;
    }

    private function detectIndent(Tokens $tokens, int $index): string
    {
        for ($i = $index; $i >= 0; $i--) {
            $t = $tokens[$i];
            if ($t->isWhitespace()) {
                $content = $t->getContent();
                $pos     = strrpos($content, "\n");
                if ($pos !== false) {
                    return substr($content, $pos + 1);
                }
            }
            if ($t->equalsAny([';', '{', '}', [T_OPEN_TAG]])) {
                break;
            }
        }

        return '';
    }

    private function ensureWhitespace(Tokens $tokens, int $index, string $content): void
    {
        if (isset($tokens[$index]) && $tokens[$index]->isWhitespace()) {
            $tokens[$index] = new Token([T_WHITESPACE, $content]);
        } else {
            $tokens->insertAt($index, [new Token([T_WHITESPACE, $content])]);
        }
    }

    private function ensureWhitespaceBefore(Tokens $tokens, int $index, string $content): void
    {
        $prev = $index - 1;
        if ($prev >= 0 && $tokens[$prev]->isWhitespace()) {
            $tokens[$prev] = new Token([T_WHITESPACE, $content]);
        } else {
            $tokens->insertAt($index, [new Token([T_WHITESPACE, $content])]);
        }
    }

    private function hasNewlineBetween(Tokens $tokens, int $start, int $end): bool
    {
        for ($i = $start + 1; $i < $end; $i++) {
            if ($tokens[$i]->isWhitespace() && str_contains($tokens[$i]->getContent(), "\n")) {
                return true;
            }
        }

        return false;
    }

    private function findSignatureStart(Tokens $tokens, int $functionIndex): int
    {
        $start = $functionIndex;
        $prev  = $tokens->getPrevMeaningfulToken($start);

        while ($prev !== null && $tokens[$prev]->isGivenKind([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT])) {
            $start = $prev;
            $prev  = $tokens->getPrevMeaningfulToken($start);
        }

        return $start;
    }

    private function findClassOpenBrace(Tokens $tokens, int $signatureStart): ?int
    {
        $prevMeaningful = $tokens->getPrevMeaningfulToken($signatureStart);
        if ($prevMeaningful !== null && $tokens[$prevMeaningful]->equals('{')) {
            return $prevMeaningful;
        }

        return null;
    }
}
