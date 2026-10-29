<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Offline;

use ChomikBox\Credentials;
use ChomikBox\Recorder\LeakScanner;
use PHPUnit\Framework\TestCase;

/**
 * No captured example may contain credentials or un-redacted secret values.
 * Failure messages name file/rule/line only.
 */
final class ExamplesSecretsTest extends TestCase
{
    public function testExamplesContainNoSecrets(): void
    {
        if (!is_dir(CHOMIK_EXAMPLES_DIR)) {
            self::markTestSkipped('No examples directory');
        }
        $cred = Credentials::available() ? Credentials::fromDirectory() : null;
        $findings = LeakScanner::scan([CHOMIK_EXAMPLES_DIR], $cred);
        $lines = array_map(static fn ($f) => basename(dirname($f['file'])) . '/' . basename($f['file']) . ':' . $f['line'] . ' ' . $f['rule'], $findings);
        self::assertSame([], $lines, 'redaction findings');
    }

    public function testScannerFlagsUnredactedValues(): void
    {
        $dir = sys_get_temp_dir() . '/chomik-leak-' . bin2hex(random_bytes(4));
        mkdir($dir . '/Op', 0777, true);
        file_put_contents("$dir/Op/x.response.xml", '<a:token>abc</a:token><a:token>{{TOKEN}}</a:token> ?t=xyz&returnUrl=/ ?t=invalid&x=1'
            . '<password>_test00</password><password>{{FOLDER_PASSWORD}}</password><password>' . LeakScanner::KNOWN_TEST_FOLDER_PASSWORD . '</password><password i:nil="true"/>');
        try {
            $rules = array_column(LeakScanner::scan([$dir]), 'rule');
            self::assertSame(['token-element', 'query-t', 'folder-password-element'], $rules);
        } finally {
            unlink("$dir/Op/x.response.xml");
            rmdir("$dir/Op");
            rmdir($dir);
        }
    }
}
