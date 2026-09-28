<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Sql;

use function array_pop;
use function array_slice;
use function count;
use function strlen;

/**
 * Раскладка SQL по строкам.
 *
 * Форматтер решает только, где переносить строки и на каком уровне отступа начинать строку. Внутри строки токены
 * соединяются так же, как в исходнике: пробел — если он был, склейка — если его не было. Переносить можно только там,
 * где в исходнике был пробельный символ или запятая, иначе форматирование отменяется (`null`).
 *
 * Узлы дерева:
 * - `['t' => SqlToken]` — токен;
 * - `['g' => SqlToken, 'c' => list<узел>, 'e' => SqlToken]` — скобки и их содержимое;
 * - `['case' => list<узел>]` — выражение CASE … END.
 */
final class SqlFormatter
{
    private SqlWriter $writer;

    public function __construct(
        private readonly int $shortSectionLength = 60,
        private readonly bool $fromBrackets = false,
    ) {
    }

    /**
     * @param list<SqlToken> $tokens
     * @return list<SqlLine>|null
     */
    public function format(array $tokens): ?array
    {
        $nodes = $this->buildTree($tokens);
        if ($nodes === null) {
            return null;
        }

        $this->writer = new SqlWriter();

        if (!$this->formatStatement($nodes, 0)) {
            return null;
        }

        return $this->writer->lines();
    }

    /**
     * @param list<SqlToken> $tokens
     * @return list<array<string, mixed>>|null
     */
    private function buildTree(array $tokens): ?array
    {
        $stack = [[]];
        $opens = [];

        foreach ($tokens as $token) {
            if ($token->type === SqlToken::OPEN) {
                $opens[] = ['g', $token];
                $stack[] = [];

                continue;
            }

            if ($token->isWord('CASE')) {
                $opens[] = ['case', $token];
                $stack[] = [['t' => $token]];

                continue;
            }

            if ($token->type === SqlToken::CLOSE) {
                $open = array_pop($opens);
                if ($open === null || $open[0] !== 'g') {
                    return null;
                }

                $children                   = array_pop($stack);
                $stack[count($stack) - 1][] = ['g' => $open[1], 'c' => $children, 'e' => $token];

                continue;
            }

            if ($token->isWord('END') && $opens !== [] && $opens[count($opens) - 1][0] === 'case') {
                array_pop($opens);
                $children                   = array_pop($stack);
                $children[]                 = ['t' => $token];
                $stack[count($stack) - 1][] = ['case' => $children];

                continue;
            }

            $stack[count($stack) - 1][] = ['t' => $token];
        }

        return $opens === [] ? $stack[0] : null;
    }

    /**
     * Запрос на уровне отступа: секции начинаются с новой строки.
     *
     * @param list<array<string, mixed>> $nodes
     */
    private function formatStatement(array $nodes, int $level): bool
    {
        $sections = $this->splitSections($nodes);
        if ($sections === null) {
            return false;
        }

        foreach ($sections as $index => [$header, $body, $type]) {
            if ($header === []) {
                // Запрос начинается не с ключевого слова: например, (SELECT …) UNION (SELECT …).
                if ($index !== 0) {
                    return false;
                }

                $this->writer->newLine($level);

                if (!$this->inline($body, $level)) {
                    return false;
                }

                continue;
            }

            // Первая секция начинается с новой строки всегда: в начале шаблона или после «(» подзапроса.
            if (!$this->startLine($level, $index === 0 ? null : $header[0])) {
                return false;
            }

            foreach ($header as $token) {
                $this->writer->append($token);
            }

            $ok = match ($type) {
                SqlKeywords::LIST => $this->formatList($body, $level, false),
                SqlKeywords::FROM => $this->formatList($body, $level, $this->fromBrackets),
                SqlKeywords::COND => $this->formatConditions($body, $level + 1),
                SqlKeywords::JOIN => $this->formatJoin($body, $level),
                default           => $this->inline($body, $level),
            };

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Делит узлы запроса на секции: [токены ключевого слова, тело, тип].
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array{0: list<SqlToken>, 1: list<array<string, mixed>>, 2: string}>|null
     */
    private function splitSections(array $nodes): ?array
    {
        $sections = [];
        $header   = [];
        $type     = SqlKeywords::INLINE;
        $body     = [];
        $count    = count($nodes);

        for ($i = 0; $i < $count; $i++) {
            $match = $this->matchSection($nodes, $i);
            if ($match === null) {
                $body[] = $nodes[$i];

                continue;
            }

            [$words, $sectionType] = $match;
            if ($header !== [] || $body !== []) {
                $sections[] = [$header, $body, $type];
            }

            $header = [];
            for ($j = 0; $j < $words; $j++) {
                $header[] = $nodes[$i + $j]['t'];
            }

            // SELECT DISTINCT / SELECT ALL — модификатор остаётся рядом с SELECT.
            $after = $nodes[$i + $words]['t'] ?? null;
            if ($header[0]->isWord('SELECT') && $after instanceof SqlToken && $after->isWord('DISTINCT', 'ALL')) {
                $header[] = $after;
                $words++;
            }

            $type = $sectionType;
            $body = [];
            $i += $words - 1;
        }

        if ($header !== [] || $body !== []) {
            $sections[] = [$header, $body, $type];
        }

        return $sections === [] ? null : $sections;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return array{0: int, 1: string}|null число слов и тип секции
     */
    private function matchSection(array $nodes, int $i): ?array
    {
        $first = $nodes[$i]['t'] ?? null;
        if (!$first instanceof SqlToken || $first->type !== SqlToken::WORD) {
            return null;
        }

        $previous = $i > 0 ? ($nodes[$i - 1]['t'] ?? null) : null;

        foreach (SqlKeywords::SECTIONS as [$words, $type, $startOnly]) {
            if ($startOnly && $i !== 0) {
                continue;
            }

            $matched = true;
            foreach ($words as $offset => $word) {
                $token = $nodes[$i + $offset]['t'] ?? null;
                if (!$token instanceof SqlToken || !$token->isWord($word)) {
                    $matched = false;

                    break;
                }
            }

            if (!$matched) {
                continue;
            }

            // «IS DISTINCT FROM», «= VALUES(a)», «, SET» — не секции; «SELECT * FROM» — секция.
            if (
                $previous instanceof SqlToken
                && (
                    $previous->isWord('DISTINCT')
                    || $previous->type === SqlToken::COMMA
                    || ($previous->type === SqlToken::OPERATOR && $previous->text !== '*')
                )
            ) {
                return null;
            }

            return [count($words), $type];
        }

        return null;
    }

    /**
     * Список через запятую: одной строкой, если короткий, иначе по элементу на строку.
     *
     * @param list<array<string, mixed>> $body
     */
    private function formatList(array $body, int $level, bool $brackets): bool
    {
        if ($body === []) {
            return true;
        }

        // Список уже в скобках (повторный запуск с from_brackets): переносим содержимое, скобки оставляем свои.
        $open  = new SqlToken(SqlToken::OPEN, '(', true, false, null, true);
        $close = new SqlToken(SqlToken::CLOSE, ')', false, false, null, true);

        if ($brackets && count($body) === 1 && isset($body[0]['g']) && !$this->isSubquery($body[0]['c'])) {
            [$open, $close] = [$body[0]['g'], $body[0]['e']];
            $inner          = $body[0]['c'];
        } else {
            $inner = $body;
        }

        $items = $this->splitByComma($inner);
        if (count($items) === 1 || $this->isShort($inner, count($items))) {
            return $this->inline($body, $level);
        }

        if ($brackets) {
            $this->writer->append($open);
        }

        foreach ($items as [$item, $comma]) {
            if (!$this->startLine($level + 1, null)) {
                return false;
            }

            if (!$this->inline($item, $level + 1)) {
                return false;
            }

            if ($comma instanceof SqlToken) {
                $this->writer->append($comma);
            }
        }

        if ($brackets) {
            $this->writer->newLine($level);
            $this->writer->append($close);
        }

        return true;
    }

    /**
     * Условия WHERE/HAVING: первое на строке секции, остальные — с новой строки, начиная со связки.
     *
     * @param list<array<string, mixed>> $body
     */
    private function formatConditions(array $body, int $conditionLevel): bool
    {
        $chunks = $this->splitConditions($body);

        foreach ($chunks as $index => $chunk) {
            if ($index > 0 && !$this->startLine($conditionLevel, $chunk[0]['t'] ?? null)) {
                return false;
            }

            if (!$this->inline($chunk, $conditionLevel - 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * JOIN: таблица и условие ON; многострочное условие оборачивается в скобки.
     *
     * @param list<array<string, mixed>> $body
     */
    private function formatJoin(array $body, int $level): bool
    {
        $onIndex = null;
        foreach ($body as $index => $node) {
            if (isset($node['t']) && $node['t']->isWord('ON')) {
                $onIndex = $index;

                break;
            }
        }

        if ($onIndex === null) {
            return $this->inline($body, $level);
        }

        $condition = array_slice($body, $onIndex + 1);

        // Условие уже целиком в скобках: переносим их содержимое.
        $wrapped = count($condition) === 1 && isset($condition[0]['g']);
        $inner   = $wrapped ? $condition[0]['c'] : $condition;
        $chunks  = $this->splitConditions($inner);

        if (count($chunks) === 1 || $this->containsLogicInSubquery($inner)) {
            return $this->inline($body, $level);
        }

        if (!$this->inline(array_slice($body, 0, $onIndex + 1), $level)) {
            return false;
        }

        $this->writer->append($wrapped ? $condition[0]['g'] : new SqlToken(SqlToken::OPEN, '(', true, false, null, true));

        foreach ($chunks as $chunk) {
            if (!$this->startLine($level + 1, null)) {
                return false;
            }

            if (!$this->inline($chunk, $level + 1)) {
                return false;
            }
        }

        $this->writer->newLine($level);
        $this->writer->append($wrapped ? $condition[0]['e'] : new SqlToken(SqlToken::CLOSE, ')', false, false, null, true));

        return true;
    }

    /**
     * Узлы в строку; подзапрос в скобках — отдельным блоком с дополнительным отступом.
     *
     * @param list<array<string, mixed>> $nodes
     */
    private function inline(array $nodes, int $level): bool
    {
        $previous = null;

        foreach ($nodes as $node) {
            if (isset($node['t'])) {
                $token = $node['t'];

                // PHP-вставка на отдельной строке после законченного операнда — самостоятельный фрагмент SQL.
                if (
                    $token->type === SqlToken::OPAQUE
                    && $token->newlineBefore
                    && $previous instanceof SqlToken
                    && $previous->endsOperand()
                ) {
                    $this->writer->newLine($level);
                }

                $this->writer->append($token);
                $previous = $token;

                continue;
            }

            if (isset($node['case'])) {
                if (!$this->inline($node['case'], $level)) {
                    return false;
                }
                $previous = $this->lastToken($node['case']);

                continue;
            }

            $this->writer->append($node['g']);

            if ($this->isSubquery($node['c'])) {
                $blockLevel = $this->writer->currentLevel();
                $saved      = $this->writer->lineCount();

                if (!$this->formatStatement($node['c'], $blockLevel + 1)) {
                    return false;
                }

                // Первая строка подзапроса начинается с новой строки после «(».
                if ($this->writer->lineCount() === $saved) {
                    return false;
                }

                $this->writer->newLine($blockLevel);
            } elseif (!$this->inline($node['c'], $level)) {
                return false;
            }

            $this->writer->append($node['e']);
            $previous = $node['e'];
        }

        return true;
    }

    /**
     * Начать новую строку перед токеном. Переносить можно только там, где в исходнике был пробельный символ.
     */
    private function startLine(int $level, ?SqlToken $first): bool
    {
        if ($this->writer->isEmpty()) {
            $this->writer->newLine($level);

            return true;
        }

        if ($first instanceof SqlToken && !$first->spaceBefore) {
            return false;
        }

        $this->writer->newLine($level);

        return true;
    }

    /**
     * @param list<array<string, mixed>> $body
     * @return list<array{0: list<array<string, mixed>>, 1: SqlToken|null}>
     */
    private function splitByComma(array $body): array
    {
        $items   = [];
        $current = [];

        foreach ($body as $node) {
            if (isset($node['t']) && $node['t']->type === SqlToken::COMMA) {
                $items[] = [$current, $node['t']];
                $current = [];

                continue;
            }

            $current[] = $node;
        }

        $items[] = [$current, null];

        return $items;
    }

    /**
     * Делит условия по связкам AND/OR/XOR верхнего уровня; AND внутри BETWEEN … AND … не делит.
     *
     * @param list<array<string, mixed>> $body
     * @return list<list<array<string, mixed>>>
     */
    private function splitConditions(array $body): array
    {
        $chunks   = [];
        $current  = [];
        $between  = false;
        $previous = null;

        foreach ($body as $node) {
            $token = $node['t'] ?? null;

            if ($token instanceof SqlToken && $token->isWord('BETWEEN')) {
                $between = true;
            }

            $startsChunk = false;
            if ($token instanceof SqlToken && $token->isWord(...SqlKeywords::CONJUNCTIONS)) {
                if ($between && $token->isWord('AND')) {
                    $between = false;
                } else {
                    $startsChunk = true;
                }
            }

            // PHP-вставка на отдельной строке после законченного условия — отдельное условие (п. 12 ТЗ).
            if (
                $token instanceof SqlToken
                && $token->type === SqlToken::OPAQUE
                && $token->newlineBefore
                && $previous instanceof SqlToken
                && $previous->endsOperand()
            ) {
                $startsChunk = true;
            }

            if ($startsChunk && $current !== []) {
                $chunks[] = $current;
                $current  = [];
            }

            $current[] = $node;
            $previous  = $token ?? (isset($node['e']) ? $node['e'] : $this->lastToken($node['case'] ?? []));
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Короткая секция сворачивается в одну строку: до трёх элементов, без связок и подзапросов, не длиннее порога.
     *
     * @param list<array<string, mixed>> $body
     */
    private function isShort(array $body, int $items): bool
    {
        if ($items > 3 || $this->containsSubquery($body)) {
            return false;
        }

        foreach ($body as $node) {
            if (isset($node['t']) && $node['t']->isWord(...SqlKeywords::CONJUNCTIONS)) {
                return false;
            }
        }

        return strlen($this->plain($body)) <= $this->shortSectionLength;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function containsSubquery(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (isset($node['g']) && ($this->isSubquery($node['c']) || $this->containsSubquery($node['c']))) {
                return true;
            }

            if (isset($node['case']) && $this->containsSubquery($node['case'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function containsLogicInSubquery(array $nodes): bool
    {
        return $this->containsSubquery($nodes);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function isSubquery(array $nodes): bool
    {
        $first = $nodes[0]['t'] ?? null;

        return $first instanceof SqlToken && $first->isWord('SELECT', 'WITH');
    }

    /**
     * Текст узлов одной строкой — для оценки длины.
     *
     * @param list<array<string, mixed>> $nodes
     */
    private function plain(array $nodes): string
    {
        $text = '';
        foreach ($this->flatten($nodes) as $index => $token) {
            $text .= ($index > 0 && $token->spaceBefore ? ' ' : '') . $token->text;
        }

        return $text;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<SqlToken>
     */
    private function flatten(array $nodes): array
    {
        $tokens = [];
        foreach ($nodes as $node) {
            if (isset($node['t'])) {
                $tokens[] = $node['t'];
            } elseif (isset($node['case'])) {
                $tokens = [...$tokens, ...$this->flatten($node['case'])];
            } else {
                $tokens = [...$tokens, $node['g'], ...$this->flatten($node['c']), $node['e']];
            }
        }

        return $tokens;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function lastToken(array $nodes): ?SqlToken
    {
        $tokens = $this->flatten($nodes);

        return $tokens === [] ? null : $tokens[count($tokens) - 1];
    }
}
