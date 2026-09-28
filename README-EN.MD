# PhpSoftBox CsFixer

Custom rules for PHP-CS-Fixer and a CliApp-based wrapper.

## CLI (psb-cs-fixer)

The CLI script is available as `vendor/bin/psb-cs-fixer` and uses a php-cs-fixer config file.

### Install in a component

Add dev dependencies:

```json
{
  "require-dev": {
    "phpsoftbox/cs-fixer": "^1.0.1",
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

### PHTML templates

For `.phtml` templates, use a dedicated config with a separate cache file:

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

The package includes `PhpSoftBox/echo_tag_html_escape`:
- scans `<?= ... ?>` / `<?php echo ... ?>` in `phtml` files;
- wraps unsafe output with `html(...)`;
- allows `raw(...)` as an explicit trusted-output bypass;
- also allows trusted `$content` and `$unsubscribeBlock` variables for email layout wrappers.

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

### SQL formatting (`PhpSoftBox/sql_format`)

Formats SQL inside PHP strings: literals, interpolated strings and concatenation chains like
`'FROM ' . $connection->table('x') . ' t'`. Not part of `defaultRules()` — enable it explicitly:

```php
protected function extendRules(array $rules): array
{
    $rules['PhpSoftBox/sql_format'] = true;
    // or with options:
    // $rules['PhpSoftBox/sql_format'] = ['max_single_line_length' => 90, 'short_section_length' => 60, 'from_brackets' => false];

    return $rules;
}
```

- short single-line SQL stays single-line, longer than `max_single_line_length` (90) is split; multiline SQL is never
  collapsed;
- multiline SQL: quotes on their own lines, sections on new lines, indented `AND`/`OR`, indented subqueries, multiline
  `ON` condition wrapped in brackets;
- lists of up to three items no longer than `short_section_length` (60) stay on one line;
- `from_brackets` wraps a multiline `FROM` list in brackets (MySQL/MariaDB only);
- single quotes, double quotes only with interpolation, variables as `{$var}`;
- expected values are left untouched: `assert*()`/`expect*()` arguments and `$expected*` variables are compared with
  generated SQL byte by byte;
- SQL comments, backslashes in literals, heredoc and a `@nofixer` comment before the statement — the string is left
  untouched. The result is re-parsed: SQL tokens and PHP expressions never change.

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
