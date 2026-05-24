<?php
/**
 * Tiny test runner: nano_section() / nano_check() / final summary.
 *
 * Mirrors the Nano CMS test harness so contributors who know one know
 * the other. No PHPUnit, no autoloader, no Composer.
 */

declare(strict_types=1);

$GLOBALS['nano_test_pass'] = 0;
$GLOBALS['nano_test_fail'] = 0;
$GLOBALS['nano_test_failures'] = [];

function nano_section(string $title): void
{
    echo "\n[$title]\n";
}

function nano_check(string $label, bool $cond): void
{
    if ($cond) {
        $GLOBALS['nano_test_pass']++;
        echo "  PASS  $label\n";
    } else {
        $GLOBALS['nano_test_fail']++;
        $GLOBALS['nano_test_failures'][] = $label;
        echo "  FAIL  $label\n";
    }
}

function nano_test_summary(): int
{
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "  TOTAL\n";
    echo str_repeat('=', 50) . "\n";
    echo "  PASSED: {$GLOBALS['nano_test_pass']}\n";
    echo "  FAILED: {$GLOBALS['nano_test_fail']}\n";
    if (!empty($GLOBALS['nano_test_failures'])) {
        echo "\nFAILURES:\n";
        foreach ($GLOBALS['nano_test_failures'] as $f) {
            echo "  - $f\n";
        }
    }
    return $GLOBALS['nano_test_fail'] === 0 ? 0 : 1;
}
