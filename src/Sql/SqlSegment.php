<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Sql;

/**
 * Сегмент SQL-шаблона: текст SQL или PHP-вставка.
 */
final readonly class SqlSegment
{
    public const string INTERPOLATION = 'interpolation';
    public const string OPERAND       = 'operand';

    private function __construct(
        public string $text,
        public ?int $opaque,
        public string $source,
        public string $kind,
    ) {
    }

    public static function text(string $text): self
    {
        return new self($text, null, '', '');
    }

    /**
     * @param string $kind INTERPOLATION — внутри строки в двойных кавычках, OPERAND — операнд конкатенации
     */
    public static function opaque(int $index, string $source, string $kind): self
    {
        return new self('', $index, $source, $kind);
    }

    public function isOpaque(): bool
    {
        return $this->opaque !== null;
    }
}
