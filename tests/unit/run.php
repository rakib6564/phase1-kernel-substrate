<?php
/**
 * Slate — unit-test runner (Phase 1, dependency-free).
 *
 * Boots ONLY the PSR-4 autoloader (no config.php, no DB), loads the harness,
 * then includes every *Test.php in this directory. Exit 0 = all passed.
 *
 * Usage: php tests/unit/run.php
 */

declare(strict_types=1);

require __DIR__ . '/../../src/autoload.php';   // Slate\ -> src/ (no side effects)
require __DIR__ . '/harness.php';

echo "# Slate unit tests\n";

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

exit(unit_summary());
