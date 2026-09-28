<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Tests;

use PhpCsFixer\Fixer\FixerInterface;
use PhpSoftBox\CsFixer\Fixers\SqlFormatFixer;
use PhpSoftBox\CsFixer\Sql\SqlFormatter;
use PhpSoftBox\CsFixer\Sql\SqlLexer;
use PhpSoftBox\CsFixer\Tests\Support\FixerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SqlFormatFixer::class)]
#[CoversClass(SqlFormatter::class)]
#[CoversClass(SqlLexer::class)]
#[CoversMethod(SqlFormatFixer::class, 'fix')]
#[CoversMethod(SqlFormatFixer::class, 'configure')]
final class SqlFormatFixerTest extends FixerTestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $configuration = [];

    /**
     * Проверим, что короткий однострочный SQL остаётся однострочным (п. 1).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function keepsShortSingleLineSql(): void
    {
        $this->assertFixed(<<<'PHP'
<?php
$row = $db->fetchOne('SELECT * FROM queue_jobs WHERE payload LIKE :payload ORDER BY id DESC LIMIT 1', $params);
PHP);
    }

    /**
     * Проверим, что однострочный SQL длиннее порога разбивается по секциям (п. 16).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function splitsLongSingleLineSql(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$sql = '
    SELECT
        id,
        payload,
        status,
        attempts,
        available_at,
        reserved_at,
        created_at
    FROM queue_jobs
    WHERE status = :status
    ORDER BY created_at DESC
    LIMIT 100
';
PHP,
            <<<'PHP'
<?php
$sql = 'SELECT id, payload, status, attempts, available_at, reserved_at, created_at FROM queue_jobs WHERE status = :status ORDER BY created_at DESC LIMIT 100';
PHP,
        );
    }

    /**
     * Проверим, что многострочный SQL получает кавычки на отдельных строках и секции с новой строки (п. 2–3).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function normalizesMultilineSql(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$row = $db->fetchOne(
    '
        SELECT *
        FROM queue_jobs
        WHERE payload LIKE :payload
        ORDER BY id DESC
    ',
    $params,
);
PHP,
            <<<'PHP'
<?php
$row = $db->fetchOne(
    'SELECT * FROM queue_jobs
        WHERE payload LIKE :payload
        ORDER BY id DESC',
    $params,
);
PHP,
        );
    }

    /**
     * Проверим, что многострочный SQL в первом аргументе вызова переносится на отдельную строку (п. 21.4.5).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function movesMultilineArgumentToOwnLine(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$row = $db->fetchOne(
    '
        SELECT *
        FROM queue_jobs
        WHERE payload LIKE :payload
    ');
PHP,
            <<<'PHP'
<?php
$row = $db->fetchOne('
    SELECT *
    FROM queue_jobs
    WHERE payload LIKE :payload
');
PHP,
        );
    }

    /**
     * Проверим, что короткий многострочный SQL не схлопывается в одну строку (п. 17).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function keepsShortMultilineSql(): void
    {
        $this->assertFixed(<<<'PHP'
<?php
$sql = '
    SELECT *
    FROM queue_jobs
    WHERE id = :id
';
PHP);
    }

    /**
     * Проверим, что длинный список SELECT пишется по элементу на строку с запятой в конце (п. 4).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function splitsSelectList(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$sql = '
    SELECT
        wsu.cell_id AS cell_id,
        COUNT(*) AS occupied_units_count,
        COALESCE(SUM(CASE WHEN wsut.kind = :product_kind THEN 1 ELSE 0 END), 0) AS product_units_count,
        COALESCE(SUM(wsu.weight_g), 0) AS current_weight_g
    FROM warehouse_storage_units wsu
';
PHP,
            <<<'PHP'
<?php
$sql = '
    SELECT wsu.cell_id AS cell_id, COUNT(*) AS occupied_units_count,
        COALESCE(SUM(CASE WHEN wsut.kind = :product_kind THEN 1 ELSE 0 END), 0) AS product_units_count
        , COALESCE(SUM(wsu.weight_g), 0) AS current_weight_g
    FROM warehouse_storage_units wsu
';
PHP,
        );
    }

    /**
     * Проверим, что короткая секция (до трёх элементов, без связок) сворачивается в одну строку (п. 21.5.2).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function collapsesShortSection(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$sql = '
    SELECT id, name
    FROM users
';
PHP,
            <<<'PHP'
<?php
$sql = '
    SELECT
        id,
        name
    FROM users
';
PHP,
        );
    }

    /**
     * Проверим, что JOIN начинаются с новой строки на уровне FROM, а условия WHERE — со связки с отступом (п. 5–7).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function formatsJoinsAndConditions(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$sql = '
    SELECT *
    FROM warehouse_storage_units wsu
    INNER JOIN warehouse_place_cells wpc ON wpc.id = wsu.cell_id
    LEFT JOIN warehouse_storage_unit_types wsut ON wsut.id = wsu.storage_unit_type_id
    WHERE wsu.warehouse_id = :warehouse_id
        AND wsu.status IN (:status_closed, :status_stored)
        AND wsu.deleted_datetime IS NULL
    GROUP BY wsu.cell_id
    HAVING COUNT(*) > 1
';
PHP,
            <<<'PHP'
<?php
$sql = '
    SELECT * FROM warehouse_storage_units wsu INNER JOIN warehouse_place_cells wpc ON wpc.id = wsu.cell_id
        LEFT JOIN warehouse_storage_unit_types wsut ON wsut.id = wsu.storage_unit_type_id
    WHERE wsu.warehouse_id = :warehouse_id AND wsu.status IN (:status_closed, :status_stored)
    AND wsu.deleted_datetime IS NULL GROUP BY wsu.cell_id HAVING COUNT(*) > 1
';
PHP,
        );
    }

    /**
     * Проверим, что AND внутри BETWEEN не переносится как новое условие.
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function keepsBetweenOnOneLine(): void
    {
        $this->assertFixed(<<<'PHP'
<?php
$sql = '
    SELECT id
    FROM orders
    WHERE created_at BETWEEN :from AND :to
        AND status = :status
';
PHP);
    }

    /**
     * Проверим, что подзапрос получает дополнительный отступ, а закрывающая скобка возвращается на уровень JOIN (п. 8).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function indentsSubquery(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$sql = '
    SELECT *
    FROM warehouse_storage_units wsu
    LEFT JOIN (
        SELECT
            storage_unit_id,
            SUM(quantity) AS quantity,
            MAX(id) AS last_id,
            MIN(id) AS first_id
        FROM warehouse_storage_unit_items
        GROUP BY storage_unit_id
    ) unit_items ON unit_items.storage_unit_id = wsu.id
';
PHP,
            <<<'PHP'
<?php
$sql = '
    SELECT *
    FROM warehouse_storage_units wsu
    LEFT JOIN (SELECT storage_unit_id, SUM(quantity) AS quantity, MAX(id) AS last_id, MIN(id) AS first_id
        FROM warehouse_storage_unit_items GROUP BY storage_unit_id) unit_items ON unit_items.storage_unit_id = wsu.id
';
PHP,
        );
    }

    /**
     * Проверим, что многострочное условие ON оборачивается в скобки (п. 21.5.3).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function wrapsMultilineOnConditionInBrackets(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$sql = '
    SELECT *
    FROM order_items i
    INNER JOIN orders o ON (
        o.id = i.order_id
        AND o.deleted_datetime IS NULL
    )
';
PHP,
            <<<'PHP'
<?php
$sql = '
    SELECT *
    FROM order_items i
    INNER JOIN orders o ON o.id = i.order_id AND o.deleted_datetime IS NULL
';
PHP,
        );
    }

    /**
     * Проверим, что при включённой опции from_brackets многострочный FROM оборачивается в скобки (п. 21.5.4).
     *
     * @see SqlFormatFixer::configure()
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function wrapsMultilineFromInBracketsWhenEnabled(): void
    {
        $this->configuration = ['from_brackets' => true];

        $this->assertFixed(
            <<<'PHP'
<?php
$sql = '
    SELECT *
    FROM (
        warehouse_storage_units wsu,
        warehouse_place_cells wpc,
        warehouse_place_rows wpr,
        warehouse_zones wz
    )
    WHERE wpc.id = wsu.cell_id
';
PHP,
            <<<'PHP'
<?php
$sql = '
    SELECT *
    FROM warehouse_storage_units wsu, warehouse_place_cells wpc, warehouse_place_rows wpr, warehouse_zones wz
    WHERE wpc.id = wsu.cell_id
';
PHP,
        );
    }

    /**
     * Проверим, что порог длины однострочного SQL настраивается.
     *
     * @see SqlFormatFixer::configure()
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function usesConfiguredSingleLineLength(): void
    {
        $this->configuration = ['max_single_line_length' => 30];

        $this->assertFixed(
            <<<'PHP'
<?php
$sql = '
    SELECT *
    FROM queue_jobs
    WHERE id = :id
';
PHP,
            <<<'PHP'
<?php
$sql = 'SELECT * FROM queue_jobs WHERE id = :id';
PHP,
        );
    }

    /**
     * Проверим, что SQL без интерполяции переводится в одинарные кавычки (п. 9).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function convertsDoubleQuotesWithoutInterpolation(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$sql = 'SELECT * FROM queue_jobs WHERE id = :id AND status = :status';
PHP,
            <<<'PHP'
<?php
$sql = "SELECT * FROM queue_jobs WHERE id = :id AND status = :status";
PHP,
        );
    }

    /**
     * Проверим, что SQL-литералы в одинарных кавычках остаются экранированными (п. 14).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function keepsEscapedSqlLiterals(): void
    {
        $this->assertFixed(<<<'PHP'
<?php
$sql = 'SELECT * FROM queue_jobs WHERE status = \'pending\'';
PHP);
    }

    /**
     * Проверим, что простая интерполяция `$var` оборачивается в фигурные скобки (п. 11).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function wrapsInterpolatedVariableInBraces(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$sql = "SELECT * FROM queue_jobs WHERE status = 'pending' {$condition}";
PHP,
            <<<'PHP'
<?php
$sql = "SELECT * FROM queue_jobs WHERE status = 'pending' $condition";
PHP,
        );
    }

    /**
     * Проверим, что интерполяция на отдельной строке остаётся отдельным условием на уровне AND (п. 12).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function keepsInterpolatedConditionOnOwnLine(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$sql = "
    SELECT *
    FROM warehouse_storage_units
    WHERE warehouse_id = :warehouse_id
        AND status = :status
        {$excludeUnitCondition}
        AND deleted_datetime IS NULL
    ORDER BY id DESC
";
PHP,
            <<<'PHP'
<?php
$sql = "
    SELECT *
    FROM warehouse_storage_units
    WHERE warehouse_id = :warehouse_id AND status = :status
        $excludeUnitCondition
    AND deleted_datetime IS NULL
    ORDER BY id DESC
";
PHP,
        );
    }

    /**
     * Проверим, что цепочка конкатенации с именами таблиц форматируется как один запрос, а выражения PHP сохраняются
     * (п. 15, 21.2).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function formatsConcatenationChain(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$rows = $connection->fetchAll(
    '
        SELECT wsu.id, wsu.barcode
        FROM ' . $connection->table('warehouse_storage_units') . ' wsu
        INNER JOIN ' . $connection->table('warehouse_storage_unit_types') . ' sut ON sut.id = wsu.storage_unit_type_id
        WHERE ' . $this->existingUnitScopeSql() . $condition . '
        ORDER BY wsu.id ASC
        LIMIT ' . max(1, min(20, $limit)),
    $params,
);
PHP,
            <<<'PHP'
<?php
$rows = $connection->fetchAll(
    'SELECT wsu.id, wsu.barcode FROM '.$connection->table('warehouse_storage_units').' wsu
        INNER JOIN ' . $connection->table('warehouse_storage_unit_types') . ' sut ON sut.id = wsu.storage_unit_type_id
            WHERE ' . $this->existingUnitScopeSql() . $condition . ' ORDER BY wsu.id ASC
        LIMIT ' . max(1, min(20, $limit)),
    $params,
);
PHP,
        );
    }

    /**
     * Проверим, что INSERT, UPDATE и SET форматируются по секциям (п. 21.4.3).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function formatsUpdateStatement(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$sql = '
    UPDATE queue_jobs
    SET
        reserved_at = :reserved_at,
        attempts = attempts + 1,
        updated_at = NOW(),
        worker = :worker
    WHERE id = :id
        AND reserved_at IS NULL
';
PHP,
            <<<'PHP'
<?php
$sql = 'UPDATE queue_jobs SET reserved_at = :reserved_at, attempts = attempts + 1, updated_at = NOW(), worker = :worker WHERE id = :id AND reserved_at IS NULL';
PHP,
        );
    }

    /**
     * Проверим, что после позиционного плейсхолдера `?` следующая секция начинается с новой строки.
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function startsSectionAfterPositionalPlaceholder(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
$sql = '
    SELECT TABLE_NAME
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = ?
        AND TABLE_NAME <> ?
    ORDER BY TABLE_NAME
';
PHP,
            <<<'PHP'
<?php
$sql = '
    SELECT TABLE_NAME
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME <> ? ORDER BY TABLE_NAME
';
PHP,
        );
    }

    /**
     * Проверим, что фрагменты условий для QueryBuilder SQL-запросом не считаются.
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function skipsConditionFragments(): void
    {
        $this->assertFixed(<<<'PHP'
<?php
$builder->where("status = :status AND deleted_datetime IS NULL AND warehouse_id = :warehouse_id AND kind = :kind");
PHP);
    }

    /**
     * Проверим, что SQL с комментарием не форматируется.
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function skipsSqlWithComments(): void
    {
        $this->assertFixed(<<<'PHP'
<?php
$sql = 'SELECT a FROM b WHERE c = 1 -- comment
    AND d = 2';
PHP);
    }

    /**
     * Проверим, что комментарий `@nofixer` отключает правило для выражения.
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function skipsDisabledStatement(): void
    {
        $this->assertFixed(<<<'PHP'
<?php
/** @nofixer SqlFixer */
$sql = 'SELECT id, payload, status, attempts, available_at, reserved_at, created_at FROM queue_jobs WHERE status = :status';
PHP);
    }

    /**
     * Проверим, что эталон в `assert*()` не форматируется: его сравнивают с SQL, который строит код, побайтно.
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function skipsAssertionArguments(): void
    {
        $this->assertFixed(<<<'PHP'
<?php
self::assertSame(
    'SELECT * FROM "tickets" AS "t" WHERE ("t"."subject" LIKE :query) OR ("t"."description" LIKE :__qb_auto_1)',
    $built['sql'],
);
self::assertSame(['sql' => 'SELECT id, payload, status, attempts, available_at, reserved_at FROM queue_jobs WHERE id = :id'], $built);
$this->expectExceptionMessage('SELECT id, payload, status, attempts, available_at, reserved_at FROM queue_jobs WHERE id = :id');
PHP);
    }

    /**
     * Проверим, что значение переменной `$expected*` не форматируется.
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function skipsExpectedVariables(): void
    {
        $this->assertFixed(<<<'PHP'
<?php
$expectedSql = 'SELECT id, payload, status, attempts, available_at, reserved_at FROM queue_jobs WHERE id = :id';
PHP);
    }

    /**
     * Проверим, что SQL в обычном вызове внутри теста форматируется: исключение только для эталонов.
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function formatsSqlInRegularCallAfterAssertion(): void
    {
        $this->assertFixed(
            <<<'PHP'
<?php
self::assertTrue($ok);
$connection->execute(
    '
        SELECT
            id,
            payload,
            status,
            attempts,
            available_at,
            reserved_at
        FROM queue_jobs
        WHERE id = :id
    ');
PHP,
            <<<'PHP'
<?php
self::assertTrue($ok);
$connection->execute('SELECT id, payload, status, attempts, available_at, reserved_at FROM queue_jobs WHERE id = :id');
PHP,
        );
    }

    /**
     * Проверим, что heredoc не изменяется (п. 21.4.4).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function skipsHeredoc(): void
    {
        $this->assertFixed(<<<'PHP'
<?php
$sql = <<<SQL
SELECT id, payload FROM queue_jobs WHERE status = :status AND attempts < :attempts ORDER BY id LIMIT 10
SQL;
PHP);
    }

    /**
     * Проверим, что полный эталон сложного запроса остаётся без изменений (п. 18, 20).
     *
     * @see SqlFormatFixer::fix()
     */
    #[Test]
    public function keepsReferenceQuery(): void
    {
        $this->assertFixed(<<<'PHP'
<?php
$rows = $this->entityManager->connection()->fetchAll(
    "
        SELECT
            wsu.cell_id AS cell_id,
            COUNT(*) AS occupied_units_count,
            COALESCE(SUM(CASE WHEN wsut.kind = :product_kind THEN 1 ELSE 0 END), 0) AS product_units_count,
            COALESCE(SUM(unit_items.quantity), 0) AS stored_items_quantity,
            COALESCE(SUM(wsu.weight_g), 0) AS current_weight_g,
            COALESCE(SUM(CASE WHEN wsu.weight_g IS NULL OR wsu.weight_g <= 0 THEN 1 ELSE 0 END), 0) AS missing_weight_units_count
        FROM warehouse_storage_units wsu
        INNER JOIN warehouse_place_cells wpc ON wpc.id = wsu.cell_id
        INNER JOIN warehouse_place_rows wpr ON wpr.id = wpc.row_id
        LEFT JOIN warehouse_storage_unit_types wsut ON wsut.id = wsu.storage_unit_type_id
        LEFT JOIN (
            SELECT
                storage_unit_id,
                SUM(quantity) AS quantity,
                MAX(created_datetime) AS last_created_datetime
            FROM warehouse_storage_unit_items
            GROUP BY storage_unit_id
        ) unit_items ON unit_items.storage_unit_id = wsu.id
        WHERE wp.warehouse_id = :warehouse_id
            AND wsu.cell_id IS NOT NULL
            AND wsu.status IN (:status_closed, :status_stored)
            {$excludeUnitCondition}
            AND wpc.deleted_datetime IS NULL
        GROUP BY wsu.cell_id
    ",
    $params,
);
PHP);
    }

    protected function createFixer(): FixerInterface
    {
        $fixer = new SqlFormatFixer();

        $fixer->configure($this->configuration);

        return $fixer;
    }

    /**
     * Результат совпадает с эталоном, а повторный запуск его не меняет (п. 20).
     */
    private function assertFixed(string $expected, ?string $input = null): void
    {
        $this->doTest($expected, $input);
        $this->doTest($expected);
    }
}
