<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Sql;

use function preg_match;
use function strlen;
use function substr;

/**
 * Разбор SQL-шаблона на токены.
 *
 * Шаблон — последовательность текстовых сегментов и PHP-вставок (интерполяция или операнд конкатенации). Вставка
 * становится одним непрозрачным токеном: её содержимое фиксер не трогает.
 *
 * Разбор консервативный: комментарии, обратные слэши в строковых литералах, dollar-quoting Postgres и несколько
 * запросов через `;` — повод не форматировать строку вовсе (`null`).
 */
final class SqlLexer
{
    /**
     * @param list<SqlSegment> $segments
     * @return list<SqlToken>|null
     */
    public function tokenize(array $segments): ?array
    {
        $tokens       = [];
        $pendingSpace = false;
        $pendingLine  = false;

        foreach ($segments as $segment) {
            if ($segment->isOpaque()) {
                $tokens[]     = new SqlToken(SqlToken::OPAQUE, $segment->source, $pendingSpace, $pendingLine, $segment->opaque);
                $pendingSpace = false;
                $pendingLine  = false;

                continue;
            }

            $text   = $segment->text;
            $length = strlen($text);
            $i      = 0;

            while ($i < $length) {
                $char = $text[$i];

                if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                    $pendingSpace = true;
                    if ($char === "\n") {
                        $pendingLine = true;
                    }
                    $i++;

                    continue;
                }

                $token = $this->readToken($text, $i, $length);
                if ($token === null) {
                    return null;
                }

                [$type, $value] = $token;
                $tokens[]       = new SqlToken($type, $value, $pendingSpace, $pendingLine);
                $pendingSpace   = false;
                $pendingLine    = false;
                $i += strlen($value);
            }
        }

        return $tokens;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function readToken(string $text, int $i, int $length): ?array
    {
        $char = $text[$i];
        $next = $i + 1 < $length ? $text[$i + 1] : '';

        // Комментарии, dollar-quoting и несколько запросов не форматируем.
        if (($char === '-' && $next === '-') || ($char === '/' && $next === '*') || $char === '#' || $char === '$' || $char === ';') {
            return null;
        }

        if ($char === '\'') {
            return $this->readQuoted($text, $i, $length, '\'', SqlToken::STRING);
        }

        if ($char === '"' || $char === '`') {
            return $this->readQuoted($text, $i, $length, $char, SqlToken::QUOTED);
        }

        if ($char === '(') {
            return [SqlToken::OPEN, '('];
        }

        if ($char === ')') {
            return [SqlToken::CLOSE, ')'];
        }

        if ($char === ',') {
            return [SqlToken::COMMA, ','];
        }

        // Позиционный плейсхолдер PDO.
        if ($char === '?') {
            return [SqlToken::PLACEHOLDER, '?'];
        }

        if ($char === ':' && $next !== ':' && preg_match('/\G:[A-Za-z_][A-Za-z0-9_]*/', $text, $match, 0, $i) === 1) {
            // «::» — приведение типа в Postgres, одиночное двоеточие перед именем — плейсхолдер.
            if ($i === 0 || $text[$i - 1] !== ':') {
                return [SqlToken::PLACEHOLDER, $match[0]];
            }
        }

        if (preg_match('/\G[0-9]+(?:\.[0-9]+)?/', $text, $match, 0, $i) === 1) {
            return [SqlToken::NUMBER, $match[0]];
        }

        if (preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/', $text, $match, 0, $i) === 1) {
            return [SqlToken::WORD, $match[0]];
        }

        // Остальное — операторы по одному символу: многосимвольные операторы сохраняются склейкой без пробела.
        return [SqlToken::OPERATOR, $char];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function readQuoted(string $text, int $start, int $length, string $quote, string $type): ?array
    {
        $i = $start + 1;
        while ($i < $length) {
            $char = $text[$i];

            // Обратный слэш трактуется в MySQL и Postgres по-разному: такие литералы не трогаем.
            if ($char === '\\') {
                return null;
            }

            if ($char === $quote) {
                // Удвоенная кавычка — экранирование внутри литерала.
                if ($i + 1 < $length && $text[$i + 1] === $quote) {
                    $i += 2;

                    continue;
                }

                return [$type, substr($text, $start, $i - $start + 1)];
            }

            $i++;
        }

        // Литерал не закрыт в этом сегменте (например, разрезан PHP-вставкой) — не форматируем.
        return null;
    }
}
