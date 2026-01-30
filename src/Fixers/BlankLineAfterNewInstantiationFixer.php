<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Fixers;

use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

use function substr_count;

use const T_NEW;
use const T_OPEN_TAG;
use const T_RETURN;
use const T_THROW;
use const T_VARIABLE;
use const T_WHITESPACE;
use const T_YIELD;

final class BlankLineAfterNewInstantiationFixer implements FixerInterface
{
    public function getName(): string
    {
        return 'PhpSoftBox/blank_line_after_new_instantiation';
    }

    public function getDefinition(): FixerDefinition
    {
        return new FixerDefinition(
            'Ensure there is a blank line after variable assignment with `new`, but not after `return new`.',
            [
                new CodeSample(
                    <<<'PHP'
<?php

$foo = new Foo();
$foo->bar();
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
            $token = $tokens[$i];
            if (!$token->equals(';')) {
                continue;
            }

            $start = $this->findStatementStart($tokens, $i);
            if ($start === null) {
                continue;
            }

            if (!$this->isVariableNewAssignment($tokens, $start, $i)) {
                continue;
            }

            // Если следующий значимый токен — '}', пропускаем (не вставляем пустую строку перед закрытием блока)
            $nextMeaningful = $tokens->getNextMeaningfulToken($i);
            if ($nextMeaningful !== null && $tokens[$nextMeaningful]->equals('}')) {
                continue;
            }

            // Вставляем пустую строку только если та же переменная используется в следующем выражении
            $eqPos  = $this->nextIndexOf($tokens, $start, '=');
            $var    = $this->findAssignedVariableName($tokens, $start, $eqPos);
            $nextSt = $this->findNextStatementBounds($tokens, $i + 1);
            if ($var !== null && $nextSt !== null) {
                [$nStart, $nEnd] = $nextSt;
                if (!$this->variableUsedInRange($tokens, $var, $nStart, $nEnd)) {
                    continue; // переменная не используется сразу — пустая строка не нужна
                }
            }

            // После ';' должен быть минимум один пустой рядок (двойной \n)
            $insertAt = $i + 1;
            if (isset($tokens[$insertAt]) && $tokens[$insertAt]->isWhitespace()) {
                $ws = $tokens[$insertAt]->getContent();
                $nl = substr_count($ws, "\n");
                if ($nl < 2) {
                    $tokens[$insertAt] = new Token([T_WHITESPACE, "\n\n"]);
                }
            } else {
                $tokens->insertAt($insertAt, [new Token([T_WHITESPACE, "\n\n"])]);
                $i++; // сдвигаем индекс вслед за вставкой
                $count = $tokens->count();
            }
        }
    }

    public function getPriority(): int
    {
        return 0;
    }

    private function findStatementStart(Tokens $tokens, int $semicolonIndex): ?int
    {
        for ($j = $semicolonIndex - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if ($t->equalsAny([';', '{', '}', [T_OPEN_TAG]])) {
                return $j + 1;
            }
        }

        return 0;
    }

    private function isVariableNewAssignment(Tokens $tokens, int $start, int $end): bool
    {
        $hasNew        = false;
        $hasAssignment = false;
        $hasVariable   = false;
        $hasReturnLike = false;

        for ($k = $start; $k <= $end; $k++) {
            $t = $tokens[$k];
            if ($t->isGivenKind(T_NEW)) {
                $hasNew = true;
            }
            if ($t->equals('=')) {
                $hasAssignment = true;
            }
            if ($t->isGivenKind(T_VARIABLE)) {
                $hasVariable = true;
            }
            if ($t->isGivenKind([T_RETURN, T_THROW, T_YIELD])) {
                $hasReturnLike = true;
            }
        }

        if (!$hasNew || !$hasAssignment || !$hasVariable || $hasReturnLike) {
            return false;
        }

        // '=' должен быть непосредственно перед выражением new (после '=' следующий значимый токен — T_NEW)
        $eqPos = $this->nextIndexOf($tokens, $start, '=');
        if ($eqPos === null) {
            return false;
        }
        $afterEq = $tokens->getNextMeaningfulToken($eqPos);
        if ($afterEq === null || !$tokens[$afterEq]->isGivenKind(T_NEW)) {
            return false; // new не является верхнеуровневым RHS
        }

        // Также проверим, что позиция new в пределах выражения
        $newPos = $this->nextIndexOf($tokens, $afterEq, [T_NEW]);

        return $newPos !== null && $newPos <= $end;
    }

    private function nextIndexOf(Tokens $tokens, int $from, $kinds): ?int
    {
        $idx   = $from;
        $limit = $tokens->count();
        while ($idx < $limit) {
            if ($tokens[$idx]->equals($kinds) || $tokens[$idx]->isGivenKind($kinds)) {
                return $idx;
            }
            $idx++;
        }

        return null;
    }

    private function findAssignedVariableName(Tokens $tokens, int $start, ?int $eqPos): ?string
    {
        if ($eqPos === null) {
            return null;
        }
        for ($k = $start; $k < $eqPos; $k++) {
            if ($tokens[$k]->isGivenKind(T_VARIABLE)) {
                return $tokens[$k]->getContent();
            }
        }

        return null;
    }

    private function findNextStatementBounds(Tokens $tokens, int $from): ?array
    {
        // Найти начало (первый значимый токен) и конец (точка с запятой) следующего выражения
        $start = $from;
        $limit = $tokens->count();
        while ($start < $limit && $tokens[$start]->isWhitespace()) {
            $start++;
        }
        if ($start >= $limit) {
            return null;
        }
        // Если сразу '}' — нет выражения
        if ($tokens[$start]->equals('}')) {
            return null;
        }
        // Ищем точку с запятой или до конца файла/блока
        for ($end = $start; $end < $limit; $end++) {
            if ($tokens[$end]->equals(';')) {
                return [$start, $end];
            }
            if ($tokens[$end]->equals('}')) {
                return [$start, $end - 1];
            }
        }

        return [$start, $limit - 1];
    }

    private function variableUsedInRange(Tokens $tokens, string $varName, int $start, int $end): bool
    {
        for ($i = $start; $i <= $end; $i++) {
            if ($tokens[$i]->isGivenKind(T_VARIABLE) && $tokens[$i]->getContent() === $varName) {
                return true;
            }
        }

        return false;
    }
}
