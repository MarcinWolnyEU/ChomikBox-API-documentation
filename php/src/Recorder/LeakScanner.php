<?php

declare(strict_types=1);

namespace ChomikBox\Recorder;

use ChomikBox\Credentials;

/**
 * Scans files for credentials and for secret-looking values that escaped the
 * Redactor. Findings never contain the secret itself: only file, rule, line.
 */
final class LeakScanner
{
    /** @var array<string, string> rule => regex for values that must always be placeholders */
    private const PATTERNS = [
        'token-element' => '#<(?:[A-Za-z]+:)?token>(?!\{\{TOKEN\}\}|NOT-A-REAL-TOKEN)[^<]+</#',
        'passHash-element' => '#<passHash>(?!\{\{(?:PASS_HASH|RANDOM_PASS_HASH|WRONG_PASS_HASH)\}\}<)[^<]+</#',
        'upload-key-element' => '#<a:key>(?!\{\{UPLOAD_TOKEN\}\})[^<]+</#',
        'upload-stamp-element' => '#<a:stamp>(?!\{\{STAMP\}\})[^<]+</#',
        'multipart-key' => '#name="key"\r?\n(?:Content-Type: text/plain\r?\n)?\r?\n(?!\{\{UPLOAD_TOKEN\}\}|f{32}\r?\n)[^\r\n]+#',
        'multipart-time' => '#name="time"\r?\n(?:Content-Type: text/plain\r?\n)?\r?\n(?!\{\{STAMP\}\})[^\r\n]+#',
        'boundary-stamp' => '#--!CHB(?!\{\{STAMP\}\}|\r|-)[0-9]+#',
        'query-t' => '#[?&]t=(?!\{\{TOKEN\}\}|invalid\b)[^&\s"\'<]+#',
        'query-key' => '#[?&]key=(?!\{\{UPLOAD_TOKEN\}\}|0{32}\b)[^&\s"\'<]+#',
        'query-sess_id' => '#[?&]sess_id=(?!\{\{SESSION_ID\}\}|\{\{TOKEN\}\})[^&\s"\'<]+#',
        'signed-e' => '#File\.aspx\?e=(?!\{\{SIGNED_E\}\})#',
        'signed-pv' => '#[?&]pv=(?!\{\{SIGNED_PV\}\})[^&\s"\'<]+#',
        'signed-url-element' => '#<(?:a:)?url>(?!\{\{SIGNED_URL\}\})[^<]+</#',
        'cookie-value' => '#(?i:set-cookie)"?,?\s*"?:?\s*"?[A-Za-z0-9_.]+=(?!\{\{COOKIE\}\})[^;\s"]+#',
        // folder passwords; KNOWN_TEST_FOLDER_PASSWORD is the value tools/capture.php set on its own
        // sandbox folder in the ModifyFolder examples captured before this rule existed
        'folder-password-element' => '#<(?:[A-Za-z]+:)?password>(?!\{\{FOLDER_PASSWORD\}\}<|' . self::KNOWN_TEST_FOLDER_PASSWORD . '<)[^<]+</#',
    ];

    /** Password chosen by the capture tool for a throw-away sandbox folder (not an account secret). */
    public const KNOWN_TEST_FOLDER_PASSWORD = 'sandbox-pass-123';

    /** @return list<array{file:string, rule:string, line:int}> */
    public static function scan(array $paths, ?Credentials $cred = null): array
    {
        $secrets = [];
        if ($cred !== null) {
            foreach ($cred->secretsForRedaction() as $placeholder => $value) {
                $secrets[$placeholder] = $value;
            }
        }
        $findings = [];
        foreach (self::files($paths) as $file) {
            $text = (string) file_get_contents($file);
            foreach ($secrets as $placeholder => $value) {
                $pos = in_array($placeholder, ['{{USERNAME}}', '{{EMAIL}}'], true) ? stripos($text, $value) : strpos($text, $value);
                if ($pos !== false) {
                    $findings[] = ['file' => $file, 'rule' => 'credential ' . $placeholder, 'line' => substr_count($text, "\n", 0, $pos) + 1];
                }
            }
            if (!self::isExample($file)) {
                continue;
            }
            foreach (self::PATTERNS as $rule => $re) {
                if (preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as [, $offset]) {
                        $findings[] = ['file' => $file, 'rule' => $rule, 'line' => substr_count($text, "\n", 0, $offset) + 1];
                    }
                }
            }
        }
        return $findings;
    }

    /** @return list<string> */
    private static function files(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            if (is_file($p)) {
                $out[] = $p;
                continue;
            }
            if (!is_dir($p)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($p, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $path = $f->getPathname();
                if ($f->isFile() && !preg_match('#[\\\\/](vendor|node_modules|\.phpunit\.cache)[\\\\/]#', $path)) {
                    $out[] = $path;
                }
            }
        }
        return $out;
    }

    private static function isExample(string $file): bool
    {
        return (bool) preg_match('#\.(request|response)\.(xml|http|html)$|\.meta\.json$#', $file);
    }
}
