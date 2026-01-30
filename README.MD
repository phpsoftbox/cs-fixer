# PhpSoftBox CsFixer

Кастомные правила для PHP-CS-Fixer и консольная оболочка на базе CliApp.

## CLI (psb-cs-fixer)

CLI-скрипт доступен как `vendor/bin/psb-cs-fixer` и использует конфиг php-cs-fixer.

### Установка в компонент

Добавьте dev-зависимости:

```json
{
  "require-dev": {
    "phpsoftbox/cs-fixer": "^0.1.0@beta",
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
