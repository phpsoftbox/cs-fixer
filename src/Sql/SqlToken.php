<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Sql;

use function in_array;
use function strtoupper;

/**
 * Токен SQL-шаблона.
 *
 * Пробельные символы токенами не являются: для каждого токена хранится только, был ли перед ним пробел или перевод
 * строки. Форматтер решает, где переносить строки, а внутри строки восстанавливает исходные пробелы.
 */
final class SqlToken
{
    public const string WORD        = 'word';
    public const string NUMBER      = 'number';
    public const string STRING      = 'string';
    public const string QUOTED      = 'quoted';
    public const string PLACEHOLDER = 'placeholder';
    public const string OPERATOR    = 'operator';
    public const string OPEN        = 'open';
    public const string CLOSE       = 'close';
    public const string COMMA       = 'comma';
    public const string OPAQUE      = 'opaque';

    public function __construct(
        public readonly string $type,
        public readonly string $text,
        public readonly bool $spaceBefore,
        public readonly bool $newlineBefore,
        public readonly ?int $opaque = null,
        public readonly bool $synthetic = false,
    ) {
    }

    public function upper(): string
    {
        return strtoupper($this->text);
    }

    public function isWord(string ...$words): bool
    {
        return $this->type === self::WORD && in_array($this->upper(), $words, true);
    }

    /**
     * Токен завершает операнд: после него может начаться следующее условие.
     */
    public function endsOperand(): bool
    {
        if ($this->type === self::WORD) {
            return !in_array($this->upper(), SqlKeywords::OPERAND_EXPECTING, true);
        }

        return in_array($this->type, [self::NUMBER, self::STRING, self::QUOTED, self::PLACEHOLDER, self::OPAQUE, self::CLOSE], true);
    }
}
