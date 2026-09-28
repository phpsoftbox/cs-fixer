# PhpSoftBox CsFixer

Кастомные правила для PHP-CS-Fixer и консольная оболочка на базе CliApp.

## CLI (psb-cs-fixer)

CLI-скрипт доступен как `vendor/bin/psb-cs-fixer` и использует конфиг php-cs-fixer.

### Установка в компонент

Добавьте dev-зависимости:

```json
{
  "require-dev": {
    "phpsoftbox/cs-fixer": "^1.0.1",
    "friendsofphp/php-cs-fixer": "^3.93",
    "phpsoftbox/cli-app": "^0.1.0@beta"
  }
}
```

### Конфиг Handler

Создайте `config/cs-fixer.php` и верните обработчик:

```php
<?php

declare(strict_types=1);

use PhpCsFixer\Finder;
use PhpSoftBox\CsFixer\Console\AbstractCsFixerHandler;

return new class extends AbstractCsFixerHandler {
    protected function getFinder(): Finder
    {
        return Finder::create()
            ->in([__DIR__ . '/../src', __DIR__ . '/../tests'])
            ->exclude('vendor')
            ->ignoreVCS(true)
            ->name('*.php');
    }

    protected function extendRules(array $rules): array
    {
        $rules['phpdoc_align'] = false;
        return $rules;
    }

    protected function extendFixers(array $fixers): array
    {
        // $fixers[] = new \Vendor\MyCustomFixer();
        return $fixers;
    }
};
```

Создайте в корне компонента `.php-cs-fixer.dist.php`:

```php
<?php

declare(strict_types=1);

use PhpSoftBox\CsFixer\Console\AbstractCsFixerHandler;

$handler = require __DIR__ . '/config/cs-fixer.php';
if (!$handler instanceof AbstractCsFixerHandler) {
    throw new RuntimeException('CsFixer handler not configured.');
}

return $handler->createConfig();
```

### Запуск

```
vendor/bin/psb-cs-fixer fix
vendor/bin/psb-cs-fixer check
```

### Проверка PHTML-шаблонов

Для шаблонов `.phtml` лучше использовать отдельный конфиг и отдельный cache-файл:

```php
<?php

declare(strict_types=1);

use PhpCsFixer\Finder;
use PhpSoftBox\CsFixer\Console\AbstractCsFixerHandler;

return new class extends AbstractCsFixerHandler {
    protected function getFinder(): Finder
    {
        return Finder::create()
            ->in([__DIR__ . '/../resources/views'])
            ->name('*.phtml');
    }

    protected function cacheFile(): ?string
    {
        return getcwd() . '/.php-cs-fixer.phtml.cache';
    }
};
```

В пакет добавлен fixer `PhpSoftBox/echo_tag_html_escape`:
- для `phtml` проверяет вывод `<?= ... ?>` / `<?php echo ... ?>`;
- небезопасный вывод автоматически оборачивает в `html(...)`;
- `raw(...)` разрешён как явный bypass для доверенного HTML;
- для email-layout дополнительно разрешены доверенные переменные `$content` и `$unsubscribeBlock`.

Поддерживаемые опции:

- `--config=<path>` (если не указано, берётся файл из корня)
- `--dry-run` (для `fix`)
- `--diff`
- `--format=<name>`
- `--path-mode=<override|intersection>`
- `--cache-file=<path>`
- `--using-cache=<yes|no>`
- `--show-progress=<type>`
- `--stop-on-violation`
- `--sequential`

### Форматирование SQL (`PhpSoftBox/sql_format`)

Правило форматирует SQL в PHP-строках: литералы, строки с интерполяцией и цепочки конкатенации вида
`'FROM ' . $connection->table('x') . ' t'`. В `defaultRules()` не входит — включается в проекте явно:

```php
protected function extendRules(array $rules): array
{
    $rules['PhpSoftBox/sql_format'] = true;
    // или с опциями:
    // $rules['PhpSoftBox/sql_format'] = ['max_single_line_length' => 90, 'short_section_length' => 60, 'from_brackets' => false];

    return $rules;
}
```

- короткий однострочный SQL остаётся однострочным, длиннее `max_single_line_length` (90) — разбивается;
  многострочный не схлопывается;
- многострочный SQL: кавычки на отдельных строках, секции с новой строки, `AND`/`OR` с отступом, подзапросы с
  дополнительным отступом, многострочное условие `ON` — в скобках;
- списки до трёх элементов не длиннее `short_section_length` (60) — одной строкой;
- `from_brackets` — скобки вокруг многострочного `FROM` (только MySQL/MariaDB);
- кавычки одинарные, двойные — только при интерполяции, переменные — `{$var}`;
- эталоны не трогаются: аргументы `assert*()`/`expect*()` и значения переменных `$expected*` сравнивают с SQL,
  который строит код, побайтно;
- комментарии в SQL, обратные слэши в литералах, heredoc и `@nofixer` в комментарии перед выражением — строка не
  трогается. Результат проверяется повторным разбором: токены SQL и выражения PHP не меняются.

### Composer scripts

Пример секции `scripts`:

```json
{
  "scripts": {
    "cs:fix": "vendor/bin/psb-cs-fixer fix",
    "cs:check": "vendor/bin/psb-cs-fixer check --diff"
  }
}
```
