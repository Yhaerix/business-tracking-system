<?php
/**
 * Lightweight Test Runner for Business Tracking System
 * Runs standalone tests with zero external dependencies.
 */

declare(strict_types=1);

class TestSuiteRunner {
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): int {
        echo "\n=== Running Business Tracking System Test Suite ===\n\n";

        $testDirs = [
            __DIR__ . '/Unit',
            __DIR__ . '/Integration'
        ];

        foreach ($testDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '/*Test.php');
            foreach ($files as $file) {
                require_once $file;
                $className = basename($file, '.php');

                if (class_exists($className)) {
                    $this->runClassTests($className);
                }
            }
        }

        echo "\n----------------------------------------------------\n";
        echo "Summary: Total Tests: " . ($this->passed + $this->failed) . " | ";
        echo "Passed: {$this->passed} | ";
        echo "Failed: {$this->failed}\n";
        echo "----------------------------------------------------\n\n";

        if ($this->failed > 0) {
            echo "FAILURES:\n";
            foreach ($this->failures as $fail) {
                echo " [FAIL] {$fail['class']}::{$fail['method']}: {$fail['message']}\n";
            }
            return 1;
        }

        echo "[SUCCESS] All tests passed cleanly.\n\n";
        return 0;
    }

    private function runClassTests(string $className): void {
        $reflection = new ReflectionClass($className);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        $instance = new $className();

        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), 'test_')) {
                $methodName = $method->getName();
                try {
                    $instance->$methodName();
                    $this->passed++;
                    echo " [PASS] {$className}::{$methodName}\n";
                } catch (Throwable $e) {
                    $this->failed++;
                    $this->failures[] = [
                        'class'   => $className,
                        'method'  => $methodName,
                        'message' => $e->getMessage()
                    ];
                    echo " [FAIL] {$className}::{$methodName} -> {$e->getMessage()}\n";
                }
            }
        }
    }
}

// Global Assertion Helpers
function assert_true(bool $condition, string $message = 'Assertion failed: expected true'): void {
    if (!$condition) {
        throw new Exception($message);
    }
}

function assert_false(bool $condition, string $message = 'Assertion failed: expected false'): void {
    if ($condition) {
        throw new Exception($message);
    }
}

function assert_equals(mixed $expected, mixed $actual, string $message = ''): void {
    if ($expected !== $actual) {
        $msg = $message ?: ("Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
        throw new Exception($msg);
    }
}

// Execute Runner if invoked via CLI
if (php_sapi_name() === 'cli') {
    $runner = new TestSuiteRunner();
    exit($runner->run());
}
