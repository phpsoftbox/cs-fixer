<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Sql;

/**
 * Строка отформатированного SQL: уровень отступа и токены.
 */
final class SqlLine
{
    /**
     * @param list<SqlToken> $tokens
     */
    public function __construct(
        public readonly int $level,
        public array $tokens = [],
    ) {
    }
}
