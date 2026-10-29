<?php

declare(strict_types=1);

namespace ChomikBox\Upload;

/**
 * The ChomikBox upload body: "multipart/mixed; boundary=!CHB<stamp>".
 *
 * Mirrors the client's part builder (called from
 * NetProtoBox::sendUploadFile) byte for byte; suffix = the UploadToken stamp:
 *
 *   part(suffix, name, value, filename, contentType):
 *     "--!CHB" suffix CRLF "name=\"" name
 *       [ "\"; filename=\"" utf8(filename) ]
 *     "\"" CRLF
 *     contentType != "" ? "Content-Type: " contentType CRLF
 *                       : (filename == "" ? "Content-Type: text/plain" CRLF : nothing)
 *     CRLF
 *     [ value CRLF ]           (only when value is non-empty)
 *   closing (empty name):  CRLF "--!CHB" suffix "--" CRLF
 *
 * There is no Content-Disposition header. The file part is sent with an empty
 * content type, so it has NO Content-Type line; its raw bytes follow the blank
 * line directly and the closing boundary follows the bytes:
 *
 *   --!CHB<stamp>  name="chomik_id"  Content-Type: text/plain  (blank)  25798223
 *   ...
 *   --!CHB<stamp>  name="file"; filename="a.txt"  (blank)  <bytes>  --!CHB<stamp>--
 *
 * The upload server also accepts a bare "!CHB" boundary (verified live): it
 * parses the parts positionally (name line, one more header line, content).
 */
final class ChbMultipart
{
    public const BOUNDARY = '!CHB';

    private string $head = '';
    private ?string $fileHeader = null;
    private string $fileData = '';
    /** @var list<string> */
    private array $names = [];

    /** @param string $boundarySuffix appended to "!CHB"; the real client uses the UploadToken stamp */
    public function __construct(public readonly string $boundarySuffix = '')
    {
    }

    public function boundary(): string
    {
        return self::BOUNDARY . $this->boundarySuffix;
    }

    public function contentType(): string
    {
        return 'multipart/mixed; boundary=' . $this->boundary();
    }

    /** A text field (Content-Type: text/plain) or, with $contentType, a binary field such as a thumbnail. */
    public function field(string $name, string|int $value, string $contentType = '', string $filename = ''): self
    {
        if ($this->fileHeader !== null) {
            throw new \LogicException('The file part must be the last part');
        }
        $this->head .= self::part($name, (string) $value, $filename, $contentType, $this->boundarySuffix);
        $this->names[] = $name;
        return $this;
    }

    /** The file itself; always the last part. */
    public function file(string $filename, string $data, string $name = 'file'): self
    {
        $this->fileHeader = self::part($name, '', $filename, '', $this->boundarySuffix);
        $this->fileData = $data;
        $this->names[] = $name;
        return $this;
    }

    /** @return list<string> part names in order */
    public function names(): array
    {
        return $this->names;
    }

    public function body(): string
    {
        return $this->head . ($this->fileHeader ?? '') . $this->fileData . self::closing($this->boundarySuffix);
    }

    public static function part(string $name, string $value, string $filename = '', string $contentType = '', string $suffix = ''): string
    {
        $s = '--' . self::BOUNDARY . $suffix . "\r\nname=\"" . $name;
        if ($filename !== '') {
            $s .= '"; filename="' . $filename;
        }
        $s .= "\"\r\n";
        if ($contentType !== '') {
            $s .= 'Content-Type: ' . $contentType . "\r\n";
        } elseif ($filename === '') {
            $s .= "Content-Type: text/plain\r\n";
        }
        $s .= "\r\n";
        if ($value !== '') {
            $s .= $value . "\r\n";
        }
        return $s;
    }

    public static function closing(string $suffix = ''): string
    {
        return "\r\n--" . self::BOUNDARY . $suffix . "--\r\n";
    }
}
