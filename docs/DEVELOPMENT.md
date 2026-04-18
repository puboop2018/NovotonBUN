# Development & Quality Tooling

The monorepo uses a layered quality-assurance pipeline:

1. **Pre-commit** (GrumPHP) — fast local checks that block bad commits
2. **CI on every push/PR** (GitHub Actions) — full suite
3. **Manual commands** (`composer …`) — run any tool on demand

## Installed tools

| Tool                  | Purpose                                | Config file              | Level/Standard     |
|-----------------------|----------------------------------------|--------------------------|--------------------|
| PHPStan               | Static analysis                        | `phpstan.neon`           | level 10 (max)     |
| Psalm                 | Static analysis (second opinion)       | `psalm.xml`              | errorLevel 5       |
| PHP_CodeSniffer       | Code style (PSR-12)                    | `phpcs.xml`              | PSR-12             |
| PHP CS Fixer          | Auto-formatter                         | `.php-cs-fixer.dist.php` | PSR-12 + PHP 8.3   |
| PHPMD                 | Mess detection (unused code, design)   | `phpmd.xml`              | custom subset      |
| GrumPHP               | Pre-commit hook runner                 | `grumphp.yml`            | —                  |
| PHPUnit               | Tests (novoton addon)                  | `addon-novoton-holidays/…/phpunit.xml` | —        |

## Quick start

```bash
# Install everything (root composer install handles all dev tools)
composer install

# Install addon test deps (only if running PHPUnit)
cd addon-novoton-holidays/app/addons/novoton_holidays && composer install

# Install the GrumPHP pre-commit hook (one-time, safe to re-run).
# Separate step so CI skips it (CI has nothing to commit).
composer hooks:install
ls .git/hooks/pre-commit   # verify
```

## Common commands

```bash
# ── Run one tool ──
composer stan          # PHPStan
composer psalm         # Psalm
composer cs            # PHPCS style check
composer fix:dry       # Show PHP CS Fixer diff (no changes written)
composer md            # PHPMD findings
composer test          # PHPUnit

# ── Auto-fix ──
composer cs:fix        # PHPCBF: fix PHPCS violations
composer fix           # PHP CS Fixer: apply formatter rules

# ── Pre-commit hook (auto-run on composer install) ──
composer hooks:install    # Re-install the GrumPHP pre-commit hook
composer hooks:uninstall  # Remove it (e.g. temporary opt-out)

# ── Baselines (after new legitimate legacy warnings) ──
composer stan:baseline # Regenerate PHPStan baseline
composer psalm:baseline # Regenerate Psalm baseline

# ── Run the full pipeline ──
composer check         # stan + psalm + cs + fix:dry + test
composer check:all     # + phpmd
```

## Pipeline rules

### PHPStan (level 10 — maximum)
- Current baseline: ~4,100 errors (tracked in `phpstan-baseline.neon`)
- **New code must pass level 10 clean** — no additions to the baseline
- Each baseline regeneration should reduce the error count

### Psalm (level 5)
- Current baseline: 0 errors (zero at levels 8, 7, 6, 5)
- Used as a second opinion — catches what PHPStan misses (immutability, purity)

### PHPCS (PSR-12)
- Zero errors allowed — CI fails on any PHPCS error
- Warnings (long lines) are informational only
- Run `composer cs:fix` to auto-fix formatting

### PHP CS Fixer
- Dry-run in CI: fails if `composer fix` would change anything
- Run `composer fix` locally before committing to format your changes

### PHPMD
- `continue-on-error: true` in CI — findings are informational, not blockers
- Flagged issues (unused local vars, unused private methods) are real bugs worth fixing
- Tuned config: excludes complexity metrics (CS-Cart dispatchers are naturally complex)

## Pre-commit hook (GrumPHP)

After `composer install`, GrumPHP installs a `.git/hooks/pre-commit` script that runs:

1. `php -l` — syntax check
2. PHPCS on changed files
3. PHP CS Fixer dry-run on changed files
4. PHPStan (full run)
5. Psalm (full run)
6. `composer validate`

Commits that fail any of these checks are **rejected locally**, before reaching
the remote. To bypass (not recommended):

```bash
git commit --no-verify -m "…"
```

## CI (GitHub Actions)

File: `.github/workflows/ci.yml`

Runs on every `push` (any branch) and `pull_request` to `main`:

| Job                | Fails CI? | What it runs                              |
|--------------------|-----------|-------------------------------------------|
| PHPStan (L10)      | yes       | `vendor/bin/phpstan analyse`              |
| Psalm              | yes       | `vendor/bin/psalm`                        |
| PHPCS (PSR-12)     | yes       | `vendor/bin/phpcs`                        |
| PHP CS Fixer       | yes       | `vendor/bin/php-cs-fixer fix --dry-run`   |
| PHPMD              | no        | `vendor/bin/phpmd`                        |
| PHPUnit (novoton)  | yes       | `vendor/bin/phpunit`                      |
| PHP Lint           | yes       | `php -l` on all `src/*.php`               |

## Fixing common issues

### "PHPStan reported new errors not in the baseline"
Write proper types or use helpers (`PriceInfoFormatter::toFloat/toInt/toScalar`
in novoton, `ValidationHelpers::toString/toFloat/toInt` in travel_core).

### "PHP CS Fixer would change this file"
Run `composer fix` to apply the formatter's suggestions. Review the diff and
commit.

### "PHPCS error: …"
Run `composer cs:fix` to auto-fix. For issues PHPCBF can't fix, see the sniff
name in parentheses (e.g. `PSR12.Classes.ClassDeclaration.ExtendsLine`) and
consult the [PSR-12 spec](https://www.php-fig.org/psr/psr-12/).

### "Psalm UndefinedFunction: fn_…"
This is expected — CS-Cart's global `fn_*` and `db_*` functions are
suppressed in `psalm.xml`. If you get this error for a *new* function, add
it to the `UndefinedFunction` handler.

### GrumPHP is too slow / blocks my commit
Individual tasks can be disabled in `grumphp.yml`. Alternatively, use
`git commit --no-verify` for the specific commit, but run `composer check`
before pushing.
