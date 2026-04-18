<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/**
 * Rector configuration — NovotonBUN monorepo.
 *
 * Gated adoption strategy (per /root/.claude/plans/i-want-to-resolve-golden-lovelace.md):
 *   - Wave 1 (this PR): scaffolding + dry-run CI, NO rules enabled. Any drift
 *     detected is an operator error, not an intentional rewrite.
 *   - Wave 3: rules whitelisted one-at-a-time, per PR. Each PR adds one rule
 *     (or a narrow pair), runs `composer rector:dry`, inspects diff, narrows
 *     `skip` until diff matches intent, then commits.
 *
 * Scope:
 *   - Paths: three addons' `src/` directories only.
 *   - Never runs on controllers/, hooks/, functions/, func.php, init.php,
 *     hooks.php, cron.php (CS-Cart procedural boundary).
 *   - Skip list excludes directories that are already clean-slate: Dto/,
 *     Enums/, ValueObjects/, Contracts/, and vendor artefacts.
 *
 * Sequencing with other tools: Rector → PHP-CS-Fixer → PHPCS → PHPStan →
 * Psalm. Rector output isn't PSR-12-clean; PHP-CS-Fixer normalises.
 */
return RectorConfig::configure()
    ->withPhpVersion(Rector\ValueObject\PhpVersion::PHP_83)
    ->withPaths([
        __DIR__ . '/addon-novoton-holidays/app/addons/novoton_holidays/src',
        __DIR__ . '/addon-travel-core/app/addons/travel_core/src',
        __DIR__ . '/addon-sphinx-holidays/app/addons/sphinx_holidays/src',
    ])
    ->withSkip([
        // Already clean-slate — these are the DTOs + VOs just shipped.
        '*/Dto/*',
        '*/ValueObjects/*',
        '*/Enums/*',
        '*/Contracts/*',

        // Vendor + caches
        '*/vendor/*',
        '*/var/*',
        '*/node_modules/*',
        '*/tests/*',
    ])
    ->withRules([
        // Empty by design — Wave 3 adds rules one-at-a-time.
    ])
    ->withImportNames(
        importNames: false,
        importDocBlockNames: false,
        importShortClasses: false,
        removeUnusedImports: false,
    );
