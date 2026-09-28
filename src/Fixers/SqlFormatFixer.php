<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Fixers;

use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpSoftBox\CsFixer\Sql\SqlFormatter;
use PhpSoftBox\CsFixer\Sql\SqlLexer;
use PhpSoftBox\CsFixer\Sql\SqlLine;
use PhpSoftBox\CsFixer\Sql\SqlSegment;
use PhpSoftBox\CsFixer\Sql\SqlToken;
use SplFileInfo;

use function array_filter;
use function array_key_last;
use function array_map;
use function count;
use function implode;
use function is_int;
use function preg_match;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strlen;
use function strrpos;
use function strspn;
use function substr;

use const T_ARRAY_CAST;
use const T_BOOL_CAST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CURLY_OPEN;
use const T_DOUBLE_ARROW;
use const T_DOUBLE_CAST;
use const T_DOUBLE_COLON;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_INT_CAST;
use const T_LNUMBER;
use const T_NS_SEPARATOR;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_RETURN;
use const T_STATIC;
use const T_STRING;
use const T_STRING_CAST;
use const T_VARIABLE;
use const T_WHITESPACE;

/**
 * Форматирование SQL в PHP-строках (ТЗ `docs/tasks/cs-fixer-sql-format.md`).
 *
 * Обрабатывает строковый литерал, строку с интерполяцией и цепочку конкатенации литералов с выражениями
 * (`'FROM ' . $connection->table('x') . ' t'`). Шаблон SQL разбирается {@see SqlLexer}, раскладывается по строкам
 * {@see SqlFormatter} и собирается обратно: PHP-выражения, их порядок и количество не меняются. Всё, что фиксер не может
 * гарантированно сохранить, остаётся без изменений.
 */
final class SqlFormatFixer implements ConfigurableFixerInterface
{
    private const string INDENT = '    ';

    private const string DETECT = '/^\s*(?:SELECT\s.*?\sFROM\s|WITH\s.*?\sAS\s*\(|UPDATE\s.*?\sSET\s|INSERT\s.*?\s(?:VALUES|SET|SELECT)\b|REPLACE\s.*?\s(?:VALUES|SET|SELECT)\b|DELETE\s+FROM\s)/is';

    private int $maxSingleLineLength = 90;

    private int $shortSectionLength = 60;

    private bool $fromBrackets = false;

    public function getName(): string
    {
        return 'PhpSoftBox/sql_format';
    }

    public function getDefinition(): FixerDefinition
    {
        return new FixerDefinition(
            'Formats SQL inside PHP strings: sections on separate lines, quotes on separate lines, single quotes unless interpolated.',
            [
                new CodeSample(
                    <<<'PHP'
<?php

$sql = 'SELECT id, payload, status, attempts, available_at, reserved_at FROM queue_jobs WHERE status = :status';
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
        return $tokens->isAnyTokenKindsFound([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]);
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    /**
     * Раньше `method_argument_space` (30): вызов с SQL-аргументом на отдельной строке делается многострочным им.
     */
    public function getPriority(): int
    {
        return 31;
    }

    public function configure(array $configuration): void
    {
        $options = $this->getConfigurationDefinition()->resolve($configuration);

        $this->maxSingleLineLength = $options['max_single_line_length'];
        $this->shortSectionLength  = $options['short_section_length'];
        $this->fromBrackets        = $options['from_brackets'];
    }

    public function getConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            new FixerOptionBuilder('max_single_line_length', 'Single-line SQL longer than this is split into multiple lines.')
                ->setAllowedTypes(['int'])
                ->setDefault(90)
                ->getOption(),
            new FixerOptionBuilder('short_section_length', 'Section lists up to this length (and up to three items) stay on one line.')
                ->setAllowedTypes(['int'])
                ->setDefault(60)
                ->getOption(),
            new FixerOptionBuilder('from_brackets', 'Wrap multiline FROM lists in brackets (MySQL/MariaDB only).')
                ->setAllowedTypes(['bool'])
                ->setDefault(false)
                ->getOption(),
        ]);
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        $candidates = [];

        for ($index = 0; $index < $tokens->count(); $index++) {
            $candidate = null;

            if ($tokens[$index]->equals('"')) {
                $candidate = $this->interpolatedCandidate($tokens, $index);
                $index     = $this->closingQuote($tokens, $index) ?? $index;
            } elseif ($tokens[$index]->isGivenKind(T_CONSTANT_ENCAPSED_STRING)) {
                $candidate = $this->chainCandidate($tokens, $index);
            }

            if ($candidate !== null) {
                $candidates[] = $candidate;
                $index        = $candidate['end'];
            }
        }

        for ($i = count($candidates) - 1; $i >= 0; $i--) {
            $this->apply($tokens, $candidates[$i]);
        }
    }

    /**
     * Строка в двойных кавычках: литерал без интерполяции или с простыми вставками `$var`, `$var->prop`, `{$expr}`.
     *
     * @return array<string, mixed>|null
     */
    private function interpolatedCandidate(Tokens $tokens, int $start): ?array
    {
        $end = $this->closingQuote($tokens, $start);
        if ($end === null || !$this->isStandalone($tokens, $start, $end)) {
            return null;
        }

        $segments = [];
        $opaque   = 0;

        for ($i = $start + 1; $i < $end; $i++) {
            $token = $tokens[$i];

            if ($token->isGivenKind(T_ENCAPSED_AND_WHITESPACE)) {
                $text = $token->getContent();
                if (str_contains($text, '\\') || str_contains($text, '{') || str_contains($text, '}')) {
                    return null;
                }

                $segments[] = SqlSegment::text($text);

                continue;
            }

            if ($token->isGivenKind(T_VARIABLE)) {
                $source = $token->getContent();
                if ($tokens[$i + 1]->isGivenKind(T_OBJECT_OPERATOR) && $tokens[$i + 2]->isGivenKind(T_STRING)) {
                    $source .= '->' . $tokens[$i + 2]->getContent();
                    $i += 2;
                } elseif ($tokens[$i + 1]->equalsAny(['[', [T_NULLSAFE_OBJECT_OPERATOR]])) {
                    return null;
                }

                $segments[] = SqlSegment::opaque($opaque++, '{' . $source . '}', SqlSegment::INTERPOLATION);

                continue;
            }

            if ($token->isGivenKind(T_CURLY_OPEN)) {
                $close = $this->findToken($tokens, $i, [CT::T_CURLY_CLOSE]);
                if ($close === null || $close >= $end) {
                    return null;
                }

                $segments[] = SqlSegment::opaque($opaque++, $this->code($tokens, $i, $close), SqlSegment::INTERPOLATION);
                $i          = $close;

                continue;
            }

            return null;
        }

        return $this->candidate($tokens, $start, $end, $segments, false);
    }

    /**
     * Литерал в одинарных или двойных кавычках и, возможно, продолжающая его цепочка конкатенации.
     *
     * @return array<string, mixed>|null
     */
    private function chainCandidate(Tokens $tokens, int $start): ?array
    {
        $segments = [];
        $opaque   = 0;
        $index    = $start;
        $literal  = true;

        while (true) {
            if ($literal) {
                $text = $this->decodeLiteral($tokens[$index]->getContent());
                if ($text === null) {
                    return null;
                }

                $segments[] = SqlSegment::text($text);
                $end        = $index;
            } else {
                $end = $this->operandEnd($tokens, $index);
                if ($end === null) {
                    return null;
                }

                $segments[] = SqlSegment::opaque($opaque++, $this->code($tokens, $index, $end), SqlSegment::OPERAND);
            }

            $next = $tokens->getNextMeaningfulToken($end);
            if ($next === null || !$tokens[$next]->equals('.')) {
                break;
            }

            $index = $tokens->getNextMeaningfulToken($next);
            if ($index === null) {
                return null;
            }

            $nextIsLiteral = $tokens[$index]->isGivenKind(T_CONSTANT_ENCAPSED_STRING);

            // Два литерала подряд или строка с интерполяцией внутри цепочки — не наш случай.
            if (($literal && $nextIsLiteral) || $tokens[$index]->equals('"')) {
                return null;
            }

            $literal = $nextIsLiteral;
        }

        if (!$this->isStandalone($tokens, $start, $end) || $this->hasComments($tokens, $start, $end)) {
            return null;
        }

        return $this->candidate($tokens, $start, $end, $segments, $opaque > 0);
    }

    /**
     * @param list<SqlSegment> $segments
     * @return array<string, mixed>|null
     */
    private function candidate(Tokens $tokens, int $start, int $end, array $segments, bool $chain): ?array
    {
        $template = '';
        $plain    = '';
        foreach ($segments as $segment) {
            $template .= $segment->isOpaque() ? 'x' : $segment->text;
            $plain .= $segment->isOpaque() ? $segment->source : $segment->text;
        }

        if (preg_match(self::DETECT, $template) !== 1 || $this->isDisabled($tokens, $start)) {
            return null;
        }

        return [
            'start'     => $start,
            'end'       => $end,
            'segments'  => $segments,
            'chain'     => $chain,
            'multiline' => str_contains($plain, "\n") || strlen($plain) > $this->maxSingleLineLength,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function apply(Tokens $tokens, array $candidate): void
    {
        $start    = $candidate['start'];
        $end      = $candidate['end'];
        $segments = $candidate['segments'];

        $previous = $tokens->getPrevMeaningfulToken($start);
        $prefix   = $this->code($tokens, $previous + 1, $start - 1);

        if (!$candidate['multiline']) {
            $pieces = array_map(
                static fn (SqlSegment $segment): string|int => $segment->isOpaque() ? $segment->opaque : $segment->text,
                $segments,
            );
        } else {
            $sources = [];
            foreach ($segments as $segment) {
                if ($segment->isOpaque()) {
                    $sources[$segment->opaque] = $segment->source;
                }
            }

            $originalTokens = new SqlLexer()->tokenize($segments);

            if ($originalTokens === null) {
                return;
            }

            $lines = new SqlFormatter($this->shortSectionLength, $this->fromBrackets)->format($originalTokens);

            if ($lines === null) {
                return;
            }

            // Аргумент вызова: кавычка переносится на отдельную строку (п. 21.4.5).
            if ($tokens[$previous]->equalsAny(['(', ',', '[']) && !str_contains($prefix, "\n")) {
                $prefix = "\n" . $this->lineIndent($tokens, $previous) . self::INDENT;
            }

            $base = str_contains($prefix, "\n") ? substr($prefix, (int) strrpos($prefix, "\n") + 1) : $this->lineIndent($tokens, $start);
            // Цепочка, которая заканчивается выражением, закрывающей кавычки не имеет.
            $closing = !$candidate['chain'] || !$segments[array_key_last($segments)]->isOpaque();
            $pieces  = $this->render($lines, $base . self::INDENT, $closing ? "\n" . $base : '');

            if (!$this->verify($originalTokens, $lines, $pieces, $sources)) {
                return;
            }
        }

        $code = $candidate['chain']
            ? $this->chainCode($pieces, $segments)
            : $this->stringCode($pieces, $segments);

        if ($code === null || $prefix . $code === $this->code($tokens, $previous + 1, $end)) {
            return;
        }

        $newTokens = $prefix === '' ? [] : [new Token([T_WHITESPACE, $prefix])];
        $generated = Tokens::fromCode('<?php ' . $code . ';');
        for ($i = 1; $i < $generated->count() - 1; $i++) {
            $newTokens[] = clone $generated[$i];
        }

        $tokens->overrideRange($previous + 1, $end, $newTokens);
    }

    /**
     * Строки форматтера в шаблон: текст и номера PHP-вставок.
     *
     * @param list<SqlLine> $lines
     * @return list<string|int>
     */
    private function render(array $lines, string $indent, string $closing): array
    {
        $pieces = [];
        $buffer = '';

        foreach ($lines as $line) {
            $buffer .= "\n" . $indent . str_repeat(self::INDENT, $line->level);
            $previous = null;

            foreach ($line->tokens as $position => $token) {
                if ($position > 0 && $this->spaced($previous, $token)) {
                    $buffer .= ' ';
                }

                if ($token->type === SqlToken::OPAQUE) {
                    $pieces[] = $buffer;
                    $pieces[] = (int) $token->opaque;
                    $buffer   = '';
                } else {
                    $buffer .= $token->text;
                }

                $previous = $token;
            }
        }

        $pieces[] = $buffer . $closing;

        return $pieces;
    }

    /**
     * Пробел перед токеном внутри строки: как в исходнике, кроме переводов строк сразу после «(», перед «)» и «,».
     */
    private function spaced(?SqlToken $previous, SqlToken $token): bool
    {
        if (!$token->spaceBefore) {
            return false;
        }

        if ($token->newlineBefore && ($token->type === SqlToken::CLOSE || $token->type === SqlToken::COMMA || $previous?->type === SqlToken::OPEN)) {
            return false;
        }

        return true;
    }

    /**
     * Проверка результата: те же токены в том же порядке, пробелы изменены только там, где это безопасно.
     *
     * @param list<SqlToken> $original
     * @param list<SqlLine> $lines
     * @param list<string|int> $pieces
     * @param array<int, string> $sources
     */
    private function verify(array $original, array $lines, array $pieces, array $sources): bool
    {
        $laid = [];
        foreach ($lines as $line) {
            foreach ($line->tokens as $token) {
                $laid[] = $token;
            }
        }

        $segments = [];
        foreach ($pieces as $piece) {
            $segments[] = is_int($piece) ? SqlSegment::opaque($piece, $sources[$piece], SqlSegment::OPERAND) : SqlSegment::text($piece);
        }

        $relexed = new SqlLexer()->tokenize($segments);

        if ($relexed === null || count($relexed) !== count($laid)) {
            return false;
        }

        $position = 0;
        $previous = null;
        foreach ($laid as $index => $token) {
            $actual = $relexed[$index];
            if ($actual->text !== $token->text || $actual->opaque !== $token->opaque) {
                return false;
            }

            if ($token->synthetic) {
                $previous = $actual;

                continue;
            }

            if (($original[$position] ?? null) !== $token) {
                return false;
            }
            $position++;

            if ($actual->spaceBefore !== $token->spaceBefore) {
                $safe = $token->type === SqlToken::CLOSE
                    || ($token->type === SqlToken::COMMA && $token->newlineBefore)
                    || $previous === null
                    || $previous->type === SqlToken::OPEN
                    || ($previous->type === SqlToken::COMMA && $actual->spaceBefore);

                if (!$safe) {
                    return false;
                }
            }

            $previous = $actual;
        }

        return $position === count($original);
    }

    /**
     * @param list<string|int> $pieces
     * @param list<SqlSegment> $segments
     */
    private function stringCode(array $pieces, array $segments): ?string
    {
        if (array_filter($pieces, is_int(...)) === []) {
            return '\'' . str_replace('\'', '\\\'', implode('', $pieces)) . '\'';
        }

        $code = '"';
        foreach ($pieces as $piece) {
            if (is_int($piece)) {
                $code .= $this->sourceOf($segments, $piece);

                continue;
            }

            if (str_contains($piece, '$') || str_contains($piece, '{') || str_contains($piece, '\\')) {
                return null;
            }

            $code .= str_replace('"', '\\"', $piece);
        }

        return $code . '"';
    }

    /**
     * Цепочка конкатенации: литералы должны остаться в тех же местах между теми же выражениями.
     *
     * @param list<string|int> $pieces
     * @param list<SqlSegment> $segments
     */
    private function chainCode(array $pieces, array $segments): ?string
    {
        // Исходная структура: литерал перед каждой вставкой и после последней.
        $hasLiteral = [];
        $slot       = 0;
        foreach ($segments as $segment) {
            if ($segment->isOpaque()) {
                $slot++;
            } else {
                $hasLiteral[$slot] = true;
            }
        }

        $parts = [];
        $slot  = 0;
        foreach ($pieces as $piece) {
            if (is_int($piece)) {
                $parts[] = $this->sourceOf($segments, $piece);
                $slot++;

                continue;
            }

            if (($piece !== '') !== isset($hasLiteral[$slot])) {
                return null;
            }

            if ($piece !== '') {
                $parts[] = '\'' . str_replace('\'', '\\\'', $piece) . '\'';
            }
        }

        return implode(' . ', $parts);
    }

    /**
     * @param list<SqlSegment> $segments
     */
    private function sourceOf(array $segments, int $opaque): string
    {
        foreach ($segments as $segment) {
            if ($segment->opaque === $opaque) {
                return $segment->source;
            }
        }

        return '';
    }

    /**
     * Текст литерала без кавычек; `null`, если в нём есть экранирование, которое фиксер не берётся переносить.
     */
    private function decodeLiteral(string $literal): ?string
    {
        $quote = $literal[0];
        if ($quote !== '\'' && $quote !== '"') {
            return null;
        }

        $body = substr($literal, 1, -1);

        if ($quote === '"') {
            return str_contains($body, '\\') || str_contains($body, '$') ? null : $body;
        }

        // Обратный слэш в SQL-литерале по-разному трактуется СУБД: такие строки не трогаем.
        $text = str_replace('\\\'', '\'', $body);

        return str_contains($text, '\\') ? null : $text;
    }

    /**
     * Конец операнда конкатенации: переменная, вызов, обращение к свойству, константе или элементу, число, выражение в
     * скобках. Всё остальное (тернарный оператор, арифметика без скобок) — не операнд.
     */
    private function operandEnd(Tokens $tokens, int $index): ?int
    {
        while ($tokens[$index]->isGivenKind([T_INT_CAST, T_STRING_CAST, T_BOOL_CAST, T_DOUBLE_CAST, T_ARRAY_CAST])) {
            $index = $tokens->getNextMeaningfulToken($index);
            if ($index === null) {
                return null;
            }
        }

        $token = $tokens[$index];

        if ($token->equals('(')) {
            $end = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index);
        } elseif ($token->isGivenKind([T_VARIABLE, T_LNUMBER])) {
            $end = $index;
        } elseif ($token->isGivenKind([T_STRING, T_NS_SEPARATOR, T_STATIC])) {
            $end = $index;
            while ($tokens[$end + 1]->isGivenKind([T_STRING, T_NS_SEPARATOR])) {
                $end++;
            }
        } else {
            return null;
        }

        while (true) {
            $next = $tokens->getNextMeaningfulToken($end);
            if ($next === null) {
                return $end;
            }

            $token = $tokens[$next];

            if ($token->equals('(')) {
                $end = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $next);
            } elseif ($token->equals('[')) {
                $end = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_INDEX_SQUARE_BRACE, $next);
            } elseif ($token->isGivenKind([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                $member = $tokens->getNextMeaningfulToken($next);
                if ($member === null || !$tokens[$member]->isGivenKind([T_STRING, T_VARIABLE, CT::T_CLASS_CONSTANT])) {
                    return null;
                }
                $end = $member;
            } else {
                return $end;
            }
        }
    }

    /**
     * Строка — самостоятельное значение: присваивание, аргумент, элемент массива или `return`, а после неё — конец
     * выражения. Иначе (`'SELECT …' === $x`, середина цепочки) не трогаем.
     */
    private function isStandalone(Tokens $tokens, int $start, int $end): bool
    {
        $previous = $tokens->getPrevMeaningfulToken($start);
        $next     = $tokens->getNextMeaningfulToken($end);

        if ($previous === null || $next === null) {
            return false;
        }

        $before = $tokens[$previous]->equalsAny(['=', '(', ',', '[', [T_DOUBLE_ARROW], [T_RETURN], [CT::T_NAMED_ARGUMENT_COLON]]);
        $after  = $tokens[$next]->equalsAny([';', ',', ')', ']']);

        return $before && $after && !$this->hasComments($tokens, $previous, $start) && !$this->isExpectedValue($tokens, $start, $previous);
    }

    /**
     * Эталон для побайтного сравнения: аргумент `assert*()`/`expect*()` (в том числе внутри массива) или значение
     * переменной `$expected*`. Такую строку сравнивают с SQL, который строит код, — пробелы в ней значимы.
     */
    private function isExpectedValue(Tokens $tokens, int $start, int $previous): bool
    {
        if ($tokens[$previous]->equals('=')) {
            $variable = $tokens->getPrevMeaningfulToken($previous);

            return $variable !== null
                && $tokens[$variable]->isGivenKind(T_VARIABLE)
                && preg_match('/^\$expected/i', $tokens[$variable]->getContent()) === 1;
        }

        for ($i = $start - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if ($token->equalsAny([';', '{', '}'])) {
                return false;
            }

            // Закрытая скобка до строки — соседний аргумент, пропускаем его целиком.
            if ($token->equals(')')) {
                $i = $tokens->findBlockStart(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $i);

                continue;
            }

            if (!$token->equals('(')) {
                continue;
            }

            $name = $tokens->getPrevMeaningfulToken($i);
            if (
                $name !== null
                && $tokens[$name]->isGivenKind(T_STRING)
                && preg_match('/^(assert|expect)/i', $tokens[$name]->getContent()) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Отключение правила комментарием `@nofixer` в выражении перед строкой.
     */
    private function isDisabled(Tokens $tokens, int $start): bool
    {
        for ($i = $start - 1; $i >= 0 && !$tokens[$i]->equalsAny([';', '{', '}']); $i--) {
            if ($tokens[$i]->isComment() && str_contains($tokens[$i]->getContent(), '@nofixer')) {
                return true;
            }
        }

        return false;
    }

    private function hasComments(Tokens $tokens, int $start, int $end): bool
    {
        for ($i = $start; $i <= $end; $i++) {
            if ($tokens[$i]->isComment()) {
                return true;
            }
        }

        return false;
    }

    private function closingQuote(Tokens $tokens, int $start): ?int
    {
        for ($i = $start + 1; $i < $tokens->count(); $i++) {
            if ($tokens[$i]->equals('"')) {
                return $i;
            }

            if ($tokens[$i]->isGivenKind(T_CURLY_OPEN)) {
                $i = $this->findToken($tokens, $i, [CT::T_CURLY_CLOSE]) ?? $i;
            }
        }

        return null;
    }

    /**
     * @param list<int> $kinds
     */
    private function findToken(Tokens $tokens, int $start, array $kinds): ?int
    {
        $depth = 0;
        for ($i = $start; $i < $tokens->count(); $i++) {
            if ($tokens[$i]->isGivenKind(T_CURLY_OPEN) || $tokens[$i]->equals('{')) {
                $depth++;
            } elseif ($tokens[$i]->isGivenKind($kinds) || $tokens[$i]->equals('}')) {
                $depth--;
                if ($depth === 0) {
                    return $tokens[$i]->isGivenKind($kinds) ? $i : null;
                }
            }
        }

        return null;
    }

    /**
     * Отступ строки, в которой находится токен.
     */
    private function lineIndent(Tokens $tokens, int $index): string
    {
        $line = '';
        for ($i = $index - 1; $i >= 0; $i--) {
            $content  = $tokens[$i]->getContent();
            $position = strrpos($content, "\n");
            if ($position !== false) {
                $line = substr($content, $position + 1) . $line;

                break;
            }

            $line = $content . $line;
        }

        $line .= $tokens[$index]->getContent();

        return substr($line, 0, strspn($line, " \t"));
    }

    private function code(Tokens $tokens, int $start, int $end): string
    {
        $code = '';
        for ($i = $start; $i <= $end; $i++) {
            $code .= $tokens[$i]->getContent();
        }

        return $code;
    }
}
