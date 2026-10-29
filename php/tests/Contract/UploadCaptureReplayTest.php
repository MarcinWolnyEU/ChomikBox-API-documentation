<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Contract;

use ChomikBox\ChomikBoxClient;
use ChomikBox\Model\UploadTokenResult;
use ChomikBox\Soap\SoapClient;
use ChomikBox\Soap\XmlNode;
use ChomikBox\Tests\Support\FakeTransport;
use ChomikBox\Tests\Support\SpecSupport;
use ChomikBox\Upload\Uploader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M5: the client's upload builder reproduces every captured upload body byte for
 * byte. Each capture is split into (name, filename, content type, value) only;
 * header lines, CRLFs, part order and the closing boundary must come out of
 * Uploader::buildRequest exactly as the server received them. Binary payloads
 * are stored as "[binary N bytes, sha256=…]" placeholders in the captures; the
 * builder treats values as opaque bytes, so the placeholder stands in for them.
 *
 * The mutation cases prove the framing checks reject the framings that are known
 * to store corrupted files.
 */
final class UploadCaptureReplayTest extends TestCase
{
    private const CAPTURE_DIR = __DIR__ . '/../../../api/examples/UploadFile';

    /** @return array<string, array{string}> */
    public static function captures(): array
    {
        $out = [];
        foreach (glob(self::CAPTURE_DIR . '/*.request.http') ?: [] as $file) {
            $out[basename($file, '.request.http')] = [$file];
        }
        return $out;
    }

    public function testEveryUploadCaptureIsReplayed(): void
    {
        self::assertCount(6, self::captures(), 'expected the six UploadFile captures; update this test if captures change');
    }

    #[DataProvider('captures')]
    public function testBuilderReproducesCapturedBody(string $file): void
    {
        $capture = self::parseCapture((string) file_get_contents($file));
        $request = self::rebuild($capture);

        self::assertSame($capture['contentType'], $request->header('Content-Type'));
        self::assertSame('POST', $request->method);
        self::assertSame('http://' . $capture['host'] . '/file/', $request->url);
        self::assertSame($capture['body'], $request->body, 'rebuilt multipart body differs from the capture');
    }

    #[DataProvider('captures')]
    public function testCapturedBodyPassesFramingChecks(string $file): void
    {
        $capture = self::parseCapture((string) file_get_contents($file));
        $spec = SpecSupport::contract();
        $stampBoundary = $capture['boundary'] !== '!CHB';

        $framing = $spec->validateChbFraming($capture['body'], $capture['contentType'], $spec->multipartEncoding('uploadFile'), $stampBoundary);
        self::assertTrue($framing->ok, $framing->summary());

        $schema = $spec->requestSchema('uploadFile', 'multipart/mixed');
        $headers = $spec->validateMultipart($capture['body'], $schema, $spec->multipartEncoding('uploadFile'));
        self::assertTrue($headers->ok, $headers->summary());
    }

    public function testContentTypeOnFilePartIsRejected(): void
    {
        $capture = self::parseCapture((string) file_get_contents(self::CAPTURE_DIR . '/success-text.request.http'));
        $mutated = preg_replace(
            '/(name="file"; filename="[^"]*"\r\n)/',
            "\$1Content-Type: application/octet-stream\r\n",
            $capture['body'],
            1,
        );
        self::assertNotSame($capture['body'], $mutated);

        $spec = SpecSupport::contract();
        $schema = $spec->requestSchema('uploadFile', 'multipart/mixed');
        $r = $spec->validateMultipart($mutated, $schema, $spec->multipartEncoding('uploadFile'));
        self::assertFalse($r->ok, 'a Content-Type line on the file part must be rejected');
    }

    public function testLfFramingIsRejected(): void
    {
        $capture = self::parseCapture((string) file_get_contents(self::CAPTURE_DIR . '/success-text.request.http'));
        $mutated = str_replace("\r\n", "\n", $capture['body']);

        $spec = SpecSupport::contract();
        $r = $spec->validateChbFraming($mutated, $capture['contentType'], $spec->multipartEncoding('uploadFile'));
        self::assertFalse($r->ok, 'LF instead of CRLF framing must be rejected');
    }

    public function testSwappedPartOrderIsRejected(): void
    {
        $capture = self::parseCapture((string) file_get_contents(self::CAPTURE_DIR . '/success-text.request.http'));
        $parts = $capture['parts'];
        [$parts[0], $parts[1]] = [$parts[1], $parts[0]]; // folder_id before chomik_id
        $mutated = self::assemble($capture['boundary'], $parts);
        self::assertNotSame($capture['body'], $mutated);

        $spec = SpecSupport::contract();
        $schema = $spec->requestSchema('uploadFile', 'multipart/mixed');
        $r = $spec->validateChbFraming($mutated, $capture['contentType'], $spec->multipartEncoding('uploadFile'), true, $schema);
        self::assertFalse($r->ok, 'parts out of the documented order must be rejected');
    }

    /** @param array{boundary: string, host: string, parts: list<array{name: string, filename: string, contentType: string, value: string}>} $capture */
    private static function rebuild(array $capture): \ChomikBox\Http\HttpRequest
    {
        $values = [];
        foreach ($capture['parts'] as $part) {
            $values[$part['name']] = $part;
        }
        foreach (array_keys($values) as $name) {
            self::assertContains($name, ['chomik_id', 'folder_id', 'key', 'time', 'width', 'height', 'th_width', 'th_height', 'thumb', 'medthumb', 'client', 'locale', 'file'], "capture has a part the replay does not model: $name");
        }

        $token = new UploadTokenResult('Ok', null, new XmlNode((new \DOMDocument())->createElement('UploadTokenResult')));
        $token->key = $values['key']['value'];
        $token->stamp = $values['time']['value'];
        $token->server = $capture['host'];
        $token->locale = $values['locale']['value'] ?? '';

        $options = [
            'boundarySuffix' => substr($capture['boundary'], strlen('!CHB')),
            'clientField' => $values['client']['value'],
            'locale' => $values['locale']['value'] ?? '',
        ];
        if (isset($values['thumb'])) {
            $options['image'] = [
                'width' => $values['width']['value'],
                'height' => $values['height']['value'],
                'thWidth' => $values['th_width']['value'],
                'thHeight' => $values['th_height']['value'],
                'thumb' => $values['thumb']['value'],
            ];
            if (isset($values['medthumb'])) {
                $options['image']['medthumb'] = $values['medthumb']['value'];
            }
        }

        $http = new FakeTransport();
        $client = new ChomikBoxClient(new SoapClient($http), $http);
        return (new Uploader($client))->buildRequest(
            $token,
            (int) $values['chomik_id']['value'],
            (int) $values['folder_id']['value'],
            $values['file']['filename'],
            $values['file']['value'],
            $options,
        );
    }

    /**
     * Splits a recorded "POST /file/" request into headers and parts. Deliberately
     * independent of OpenApiContract::validateChbFraming, so the replay does not
     * reuse the code it checks.
     *
     * @return array{contentType: string, boundary: string, host: string, body: string, parts: list<array{name: string, filename: string, contentType: string, value: string}>}
     */
    private static function parseCapture(string $raw): array
    {
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        self::assertSame(1, preg_match('/^Content-Type: (multipart\/mixed; boundary=(!CHB[^\r\n]*))\r?$/mi', $head, $ct));
        self::assertSame(1, preg_match('/^Host: ([^\r\n]+)\r?$/mi', $head, $host));
        $boundary = $ct[2];

        $closing = "\r\n--$boundary--\r\n";
        self::assertStringStartsWith("--$boundary\r\n", $body);
        self::assertStringEndsWith($closing, $body);
        $inner = substr($body, strlen("--$boundary\r\n"), -strlen($closing));

        $parts = [];
        foreach (explode("\r\n--$boundary\r\n", $inner) as $chunk) {
            if (str_contains($chunk, "\r\n\r\n")) {
                [$hdr, $value] = explode("\r\n\r\n", $chunk, 2);
            } else {
                // empty value: the blank line's CRLF was consumed by the separator
                [$hdr, $value] = [substr($chunk, 0, -2), ''];
            }
            $lines = explode("\r\n", $hdr);
            self::assertSame(1, preg_match('/^name="([^"]*)"(?:; filename="([^"]*)")?$/', $lines[0], $n), "bad part header: {$lines[0]}");
            $contentType = '';
            foreach (array_slice($lines, 1) as $line) {
                self::assertSame(1, preg_match('/^Content-Type: (.+)$/', $line, $t), "unexpected part header line: $line");
                $contentType = $t[1];
            }
            $parts[] = ['name' => $n[1], 'filename' => $n[2] ?? '', 'contentType' => $contentType, 'value' => $value];
        }

        return ['contentType' => $ct[1], 'boundary' => $boundary, 'host' => $host[1], 'body' => $body, 'parts' => $parts];
    }

    /** @param list<array{name: string, filename: string, contentType: string, value: string}> $parts */
    private static function assemble(string $boundary, array $parts): string
    {
        $out = '';
        $last = count($parts) - 1;
        foreach ($parts as $i => $p) {
            $out .= "--$boundary\r\nname=\"{$p['name']}\"" . ($p['filename'] !== '' ? "; filename=\"{$p['filename']}\"" : '') . "\r\n";
            $out .= $p['contentType'] !== '' ? "Content-Type: {$p['contentType']}\r\n" : '';
            $out .= "\r\n" . $p['value'] . ($i === $last ? '' : "\r\n");
        }
        return $out . "\r\n--$boundary--\r\n";
    }
}
