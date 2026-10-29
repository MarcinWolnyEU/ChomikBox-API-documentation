<?php

declare(strict_types=1);

namespace ChomikBox\Recorder;

/**
 * Replaces secrets with stable placeholders before anything is written.
 *
 * Three layers:
 *  1. Known secrets (username, e-mail, password, passHash) from Credentials.
 *  2. Values learned at runtime (session token, upload keys, stamps...), either
 *     registered explicitly or harvested from SOAP/HTTP payloads by learn().
 *  3. Pattern rules for things that are secret by position (query parameters
 *     like t=, sess_id=, cookies, signed download URLs).
 *
 * assertClean() is the fail-closed guard: the recorder refuses to write if a
 * known secret survived.
 */
final class Redactor
{
    /**
     * Secret value => [placeholder, caseInsensitive]. Keys are prefixed with "s:"
     * so numeric secrets (e.g. tick-based stamps) never become int array keys.
     *
     * @var array<string, array{0:string, 1:bool}>
     */
    private array $values = [];

    /** XML elements whose text content is always a secret, local name => placeholder. */
    private const SECRET_ELEMENTS = [
        'token' => '{{TOKEN}}',
        'passHash' => '{{PASS_HASH}}',
        'sessionId' => '{{SESSION_ID}}',
        'sess_id' => '{{SESSION_ID}}',
        'email' => '{{EMAIL}}',
        'seed' => '{{SEED}}',
        // folder passwords in FolderInfo listings / ModifyFolder (the server also
        // generates one for playlist folders); nil and empty values are untouched
        'password' => '{{FOLDER_PASSWORD}}',
    ];

    /**
     * Elements that are secret only inside a given result type (a <stamp> in a
     * Download MessageSequence is a harmless client counter; in UploadTokenResult
     * it is an upload credential). Scope element => [local name => placeholder].
     */
    private const SCOPED_ELEMENTS = [
        'UploadTokenResult' => ['key' => '{{UPLOAD_TOKEN}}', 'stamp' => '{{STAMP}}'],
        'FileEntry' => ['url' => '{{SIGNED_URL}}'],
        'RefreshFileUrlResult' => ['url' => '{{SIGNED_URL}}'],
    ];

    /** Query parameters whose value is a secret. */
    private const SECRET_QUERY = [
        't' => '{{TOKEN}}',
        'token' => '{{TOKEN}}',
        'key' => '{{UPLOAD_TOKEN}}',
        'stamp' => '{{STAMP}}',
        'sess_id' => '{{SESSION_ID}}',
        'sessid' => '{{SESSION_ID}}',
        'seed' => '{{SEED}}',
        'passHash' => '{{PASS_HASH}}',
        // signed file-server URLs: http://sNNNN.chomikuj.pl/File.aspx?e=...&pv=...
        'e' => '{{SIGNED_E}}',
        'pv' => '{{SIGNED_PV}}',
    ];

    /**
     * Literal non-secret values that position rules must leave alone: deliberate
     * fake tokens used for error examples, and the client's own "t=invalid".
     */
    private const PUBLIC_VALUES = '(?:NOT-A-REAL-[A-Za-z0-9-]*|invalid(?=[&\s"\'<]|$))';

    /** @param array<string, string> $secrets placeholder => value */
    public function __construct(array $secrets = [])
    {
        foreach ($secrets as $placeholder => $value) {
            $this->register($value, $placeholder, in_array($placeholder, ['{{USERNAME}}', '{{EMAIL}}'], true));
        }
    }

    public function register(?string $value, string $placeholder, bool $caseInsensitive = false): void
    {
        if ($value === null || strlen($value) < 4 || str_starts_with($value, '{{')
            || preg_match('#^' . self::PUBLIC_VALUES . '$#', $value)) {
            return;
        }
        // The value itself plus common encodings of it. The first registration wins, so an
        // explicit placeholder (e.g. {{WRONG_PASS_HASH}}) is not overwritten by learn().
        foreach ([$value, rawurlencode($value), urlencode($value), htmlspecialchars($value, ENT_XML1 | ENT_QUOTES)] as $form) {
            $this->values['s:' . $form] ??= [$placeholder, $caseInsensitive];
        }
    }

    /** Harvest secret values from an XML/HTTP payload so later redaction catches them anywhere. */
    public function learn(string $payload): void
    {
        foreach (self::elementRules($payload) as $local => $placeholder) {
            if (preg_match_all('#<(?:[A-Za-z0-9_]+:)?' . preg_quote($local, '#') . '(?:\s[^>]*)?>([^<]+)</#', $payload, $m)) {
                foreach ($m[1] as $v) {
                    $this->register(html_entity_decode(trim($v), ENT_XML1 | ENT_QUOTES), $placeholder);
                }
            }
        }
        foreach (self::SECRET_QUERY as $param => $placeholder) {
            if (preg_match_all('#[?&;]' . preg_quote($param, '#') . '=([^&\s"\'<>]+)#', $payload, $m)) {
                foreach ($m[1] as $v) {
                    $this->register(rawurldecode($v), $placeholder);
                    $this->register($v, $placeholder);
                }
            }
        }
    }

    public function redact(string $text): string
    {
        // Longest first so that a value containing another is replaced whole.
        $values = $this->values;
        uksort($values, static fn (string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($values as $key => [$placeholder, $ci]) {
            $secret = substr($key, 2);
            $text = $ci ? str_ireplace($secret, $placeholder, $text) : str_replace($secret, $placeholder, $text);
        }

        // Position-based rules (values we may not have learned).
        foreach (self::elementRules($text) as $local => $placeholder) {
            $text = preg_replace(
                '#(<(?:[A-Za-z0-9_]+:)?' . preg_quote($local, '#') . '(?:\s[^>/]*)?>)(?!\{\{|' . self::PUBLIC_VALUES . ')[^<]+(</)#',
                '$1' . $placeholder . '$2',
                $text,
            ) ?? $text;
        }
        foreach (self::SECRET_QUERY as $param => $placeholder) {
            $text = preg_replace(
                '#([?&;]' . preg_quote($param, '#') . '=)(?!\{\{|' . self::PUBLIC_VALUES . ')[^&\s"\'<>]+#',
                '$1' . $placeholder,
                $text,
            ) ?? $text;
        }
        return $text;
    }

    /** @return array<string, string> element rules applicable to this payload */
    private static function elementRules(string $payload): array
    {
        $rules = self::SECRET_ELEMENTS;
        foreach (self::SCOPED_ELEMENTS as $scope => $elements) {
            if (str_contains($payload, $scope)) {
                $rules += $elements;
            }
        }
        return $rules;
    }

    /** Redacts a header value; cookies keep their names and attributes only. */
    public function redactHeader(string $name, string $value): string
    {
        $lname = strtolower($name);
        if ($lname === 'set-cookie') {
            $parts = explode(';', $value);
            $first = array_shift($parts);
            [$cname] = explode('=', $first, 2) + [1 => ''];
            return trim($cname) . '={{COOKIE}}' . ($parts ? ';' . implode(';', $parts) : '');
        }
        if ($lname === 'cookie') {
            $pairs = array_map('trim', explode(';', $value));
            return implode('; ', array_map(static fn ($p) => explode('=', $p, 2)[0] . '={{COOKIE}}', $pairs));
        }
        return $this->redact($value);
    }

    /** @throws \LogicException if any registered secret still occurs in $text */
    public function assertClean(string $text): void
    {
        foreach ($this->values as $key => [$placeholder, $ci]) {
            $secret = substr($key, 2);
            $found = $ci ? stripos($text, $secret) : strpos($text, $secret);
            if ($found !== false) {
                throw new \LogicException("Redaction failure: a value for $placeholder survived redaction");
            }
        }
    }
}
