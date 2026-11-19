<?php
/**
 * Test runner, no dependencies:
 *
 *     php tests/run.php              # unit tests (no network, no API)
 *     php tests/run.php integration  # the suite against a local copy of the Developer API
 *     php tests/run.php all          # both
 *
 * The integration suite needs WOLVY_IT_FIXTURE (see tests/integration/suite.php).
 * Exits 1 when any check fails.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/bootstrap.php';

$mode = isset($argv[1]) ? $argv[1] : 'unit';
if (!in_array($mode, ['unit', 'integration', 'all'], true)) {
    fwrite(STDERR, "usage: php tests/run.php [unit|integration|all]\n");
    exit(2);
}

echo 'wolvy/wolvy-php ', \Wolvy\Client::VERSION, ' tests (', $mode, ')', PHP_EOL;
echo 'PHP ', PHP_VERSION, ' · autoloader: ', WOLVY_TEST_AUTOLOADER, PHP_EOL;

if ($mode === 'unit' || $mode === 'all') {
    foreach (glob(__DIR__ . '/unit/*.php') as $file) {
        require $file;
    }
}
if ($mode === 'integration' || $mode === 'all') {
    require __DIR__ . '/integration/suite.php';
}

echo PHP_EOL, T::$passed, ' passed, ', T::$failed, ' failed', PHP_EOL;
if (T::$failed > 0) {
    echo PHP_EOL, 'Failures:', PHP_EOL;
    foreach (T::$failures as $failure) {
        echo '  - ', $failure, PHP_EOL;
    }
    exit(1);
}
exit(0);
