# PhpSoftBox CsFixer

Custom rules for PHP-CS-Fixer and a CliApp-based wrapper.

## CLI (psb-cs-fixer)

The CLI script is available as `vendor/bin/psb-cs-fixer` and uses a php-cs-fixer config file.

### Install in a component

Add dev dependencies:

```json
{
  "require-dev": {
    "phpsoftbox/cs-fixer": "^0.1.0@beta",
    "friendsofphp/php-cs-fixer": "^3.93",
    "phpsoftbox/cli-app": "^0.1.0@beta"
  }
}
```

### Handler config

Create `config/cs-fixer.php` and return a handler instance:

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

Create `.php-cs-fixer.dist.php` in the component root:

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

### Run

```
vendor/bin/psb-cs-fixer fix
vendor/bin/psb-cs-fixer check
```

Supported options:

- `--config=<path>` (if omitted, config is resolved from the project root)
- `--dry-run` (for `fix`)
- `--diff`
- `--format=<name>`
- `--path-mode=<override|intersection>`
- `--cache-file=<path>`
- `--using-cache=<yes|no>`
- `--show-progress=<type>`
- `--stop-on-violation`
- `--sequential`

### Composer scripts

Example `scripts` section:

```json
{
  "scripts": {
    "cs:fix": "vendor/bin/psb-cs-fixer fix",
    "cs:check": "vendor/bin/psb-cs-fixer check --diff"
  }
}
```
