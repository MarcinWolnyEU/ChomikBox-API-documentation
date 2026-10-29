<?php

declare(strict_types=1);

/**
 * Redaction check over everything this project wrote. Prints file/rule/line
 * of each finding, never the secret value.
 *
 *   php tools/leak-check.php [path ...]
 */

ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/vendor/autoload.php';

use ChomikBox\Credentials;
use ChomikBox\Recorder\LeakScanner;

$root = dirname(__DIR__, 2);
// Credentials are compared against every file under these paths; the placeholder
// pattern rules apply to capture files (*.request.*, *.response.*, *.meta.json).
// api/ covers both spec documents (chomikbox.openapi.* and chomikbox.per-operation.openapi.*)
// and the examples; vendor/, node_modules/ and .phpunit.cache/ are skipped.
$paths = array_slice($argv, 1) ?: [
    $root . '/php',
    $root . '/api',
    $root . '/.tmp/notes',
    $root . '/.tmp/review',
    $root . '/.tmp/live-scratch',
    $root . '/site',
];
$cred = Credentials::available() ? Credentials::fromDirectory() : null;
$findings = LeakScanner::scan($paths, $cred);
foreach ($findings as $f) {
    printf("LEAK %s:%d  %s\n", $f['file'], $f['line'], $f['rule']);
}
printf("%s: %d finding(s) in %s (credentials %s)\n",
    $findings ? 'FAIL' : 'OK', count($findings), implode(', ', array_map('basename', $paths)), $cred ? 'checked' : 'not available');
exit($findings ? 1 : 0);
