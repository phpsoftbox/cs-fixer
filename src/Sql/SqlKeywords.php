<?php

declare(strict_types=1);

namespace PhpSoftBox\CsFixer\Sql;

/**
 * Ключевые слова, по которым SQL разбивается на секции.
 */
final class SqlKeywords
{
    public const string LIST   = 'list';
    public const string COND   = 'cond';
    public const string FROM   = 'from';
    public const string JOIN   = 'join';
    public const string INLINE = 'inline';
    public const string ALONE  = 'alone';

    /**
     * Секции: слова ключевого слова → тип секции. Порядок — от длинных к коротким.
     *
     * @var list<array{0: list<string>, 1: string, 2: bool}> слова, тип, допустима только в начале запроса
     */
    public const array SECTIONS = [
        [['ON', 'DUPLICATE', 'KEY', 'UPDATE'], self::LIST, false],
        [['LEFT', 'OUTER', 'JOIN'], self::JOIN, false],
        [['RIGHT', 'OUTER', 'JOIN'], self::JOIN, false],
        [['FULL', 'OUTER', 'JOIN'], self::JOIN, false],
        [['INSERT', 'IGNORE', 'INTO'], self::INLINE, true],
        [['INSERT', 'INTO'], self::INLINE, true],
        [['REPLACE', 'INTO'], self::INLINE, true],
        [['DELETE', 'FROM'], self::INLINE, true],
        [['INNER', 'JOIN'], self::JOIN, false],
        [['CROSS', 'JOIN'], self::JOIN, false],
        [['LEFT', 'JOIN'], self::JOIN, false],
        [['RIGHT', 'JOIN'], self::JOIN, false],
        [['FULL', 'JOIN'], self::JOIN, false],
        [['NATURAL', 'JOIN'], self::JOIN, false],
        [['GROUP', 'BY'], self::LIST, false],
        [['ORDER', 'BY'], self::LIST, false],
        [['UNION', 'ALL'], self::ALONE, false],
        [['ON', 'CONFLICT'], self::INLINE, false],
        [['FOR', 'UPDATE'], self::INLINE, false],
        [['STRAIGHT_JOIN'], self::JOIN, false],
        [['JOIN'], self::JOIN, false],
        [['WITH'], self::INLINE, true],
        [['SELECT'], self::LIST, false],
        [['UPDATE'], self::INLINE, true],
        [['FROM'], self::FROM, false],
        [['WHERE'], self::COND, false],
        [['HAVING'], self::COND, false],
        [['LIMIT'], self::INLINE, false],
        [['OFFSET'], self::INLINE, false],
        [['UNION'], self::ALONE, false],
        [['INTERSECT'], self::ALONE, false],
        [['EXCEPT'], self::ALONE, false],
        [['RETURNING'], self::LIST, false],
        [['VALUES'], self::LIST, false],
        [['SET'], self::LIST, false],
        [['WINDOW'], self::INLINE, false],
    ];

    /**
     * Логические связки условий.
     *
     * @var list<string>
     */
    public const array CONJUNCTIONS = ['AND', 'OR', 'XOR'];

    /**
     * Слова, после которых ожидается операнд: вставка PHP после них — продолжение выражения, а не новое условие.
     *
     * @var list<string>
     */
    public const array OPERAND_EXPECTING = [
        'AND', 'OR', 'XOR', 'NOT', 'WHERE', 'HAVING', 'ON', 'IN', 'IS', 'LIKE', 'ILIKE', 'BETWEEN', 'SELECT', 'FROM',
        'JOIN', 'BY', 'SET', 'VALUES', 'CASE', 'WHEN', 'THEN', 'ELSE', 'AS', 'EXISTS', 'ANY', 'ALL', 'SOME', 'DISTINCT',
        'LIMIT', 'OFFSET', 'INTO', 'UPDATE', 'REGEXP', 'RLIKE', 'ESCAPE', 'USING',
    ];
}
