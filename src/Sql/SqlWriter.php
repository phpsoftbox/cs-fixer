<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Sql;

use function count;

/**
 * Накопитель строк форматтера.
 */
final class SqlWriter
{
    /**
     * @var list<SqlLine>
     */
    private array $lines = [];

    public function newLine(int $level): void
    {
        $this->lines[] = new SqlLine($level);
    }

    public function append(SqlToken $token): void
    {
        if ($this->lines === []) {
            $this->newLine(0);
        }

        $this->lines[count($this->lines) - 1]->tokens[] = $token;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function currentLevel(): int
    {
        return $this->lines === [] ? 0 : $this->lines[count($this->lines) - 1]->level;
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }

    /**
     * @return list<SqlLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }
}
