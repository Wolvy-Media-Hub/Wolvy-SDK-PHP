<?php
/**
 * A zero-dependency test harness (PHPUnit versions differ too much across PHP 7.4 to 8.5).
 * Each check prints one line; run.php exits non-zero when any fails.
 */

final class T
{
    /** @var int */
    public static $passed = 0;
    /** @var int */
    public static $failed = 0;
    /** @var string[] */
    public static $failures = [];
    /** @var string */
    private static $section = '';

    public static function section(string $name): void
    {
        self::$section = $name;
        echo PHP_EOL, '## ', $name, PHP_EOL;
    }

    public static function ok(bool $condition, string $label, string $detail = ''): bool
    {
        if ($condition) {
            self::$passed++;
            echo '  ok    ', $label, PHP_EOL;
            return true;
        }
        self::$failed++;
        self::$failures[] = self::$section . ': ' . $label . ($detail !== '' ? ' — ' . $detail : '');
        echo '  FAIL  ', $label, ($detail !== '' ? PHP_EOL . '        ' . $detail : ''), PHP_EOL;
        return false;
    }

    /**
     * @param mixed $actual
     * @param mixed $expected
     */
    public static function same($actual, $expected, string $label): bool
    {
        return self::ok($actual === $expected, $label, $actual === $expected ? '' : 'expected ' . self::show($expected) . ', got ' . self::show($actual));
    }

    /**
     * Runs $fn and checks that it throws $class; $check may inspect the exception.
     *
     * @param callable(): mixed            $fn
     * @param callable(\Throwable): bool|null $check
     */
    public static function throws(callable $fn, string $class, string $label, ?callable $check = null): ?\Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                self::ok(false, $label, 'expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
                return $e;
            }
            if ($check !== null && $check($e) !== true) {
                self::ok(false, $label, 'the ' . get_class($e) . ' did not pass the check: ' . $e->getMessage());
                return $e;
            }
            self::ok(true, $label);
            return $e;
        }
        self::ok(false, $label, 'expected ' . $class . ', nothing was thrown');
        return null;
    }

    /**
     * Runs a block; an unexpected exception is one failure, not the end of the run.
     *
     * @param callable(): void $fn
     */
    public static function run(string $label, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            self::ok(false, $label . ' (unexpected exception)', get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /** @param mixed $value */
    public static function show($value): string
    {
        $out = var_export($value, true);
        return strlen($out) > 300 ? substr($out, 0, 300) . '…' : $out;
    }
}
