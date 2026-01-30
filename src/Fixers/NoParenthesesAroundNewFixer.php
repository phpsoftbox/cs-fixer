<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Fixers;

use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

use const T_NEW;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;

final class NoParenthesesAroundNewFixer implements FixerInterface
{
    public function getName(): string
    {
        return 'PhpSoftBox/no_parentheses_around_new';
    }

    public function getDefinition(): FixerDefinition
    {
        return new FixerDefinition(
            'Remove redundant parentheses around `new` expression when it is immediately chained (e.g., (new X())->m() => new X()->m()).',
            [
                new CodeSample(
                    <<<'PHP'
<?php
$expires = (new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
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
        return $tokens->isTokenKindFound(T_NEW);
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        $count = $tokens->count();
        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->isGivenKind(CT::T_BRACE_CLASS_INSTANTIATION_OPEN)) {
                continue;
            }

            $nextMeaningful = $tokens->getNextMeaningfulToken($i);
            if ($nextMeaningful === null || !$tokens[$nextMeaningful]->isGivenKind(T_NEW)) {
                continue;
            }

            $closeParen = $this->findBraceClassInstantiationClose($tokens, $i);
            if ($closeParen === null) {
                continue;
            }

            // Следующий значимый токен после ')' должен быть оператором -> или ?->
            $after = $tokens->getNextMeaningfulToken($closeParen);
            if ($after === null) {
                continue;
            }
            $isChain = $tokens[$after]->isGivenKind(T_OBJECT_OPERATOR) || $tokens[$after]->isGivenKind(T_NULLSAFE_OBJECT_OPERATOR) || $tokens[$after]->equals('->') || $tokens[$after]->equals('?->');
            if (!$isChain) {
                continue;
            }

            // Удаляем только внешние скобки, оставляя содержимое нетронутым
            // Возможен пробел сразу после '(' или перед ')', поэтому аккуратно чистим whitespace
            $this->removeParenAt($tokens, $closeParen);
            $this->removeParenAt($tokens, $i);

            // Сдвинем счётчик, так как количество токенов изменилось
            $count = $tokens->count();
        }
    }

    public function getPriority(): int
    {
        return 0;
    }

    private function removeParenAt(Tokens $tokens, int $index): void
    {
        // Удаляем саму скобку и возможные нулевые whitespace вокруг неё
        $tokens->clearAt($index);
        // Нормализуем двойные пробелы, если они возникли, но в данном правиле это минимально инвазивно
    }

    private function findBraceClassInstantiationClose(Tokens $tokens, int $openIndex): ?int
    {
        $level = 0;
        $count = $tokens->count();
        for ($i = $openIndex; $i < $count; $i++) {
            if ($tokens[$i]->isGivenKind(CT::T_BRACE_CLASS_INSTANTIATION_OPEN)) {
                $level++;
                continue;
            }
            if ($tokens[$i]->isGivenKind(CT::T_BRACE_CLASS_INSTANTIATION_CLOSE)) {
                $level--;
                if ($level === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
