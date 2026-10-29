<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Offline;

use ChomikBox\Credentials;
use ChomikBox\Http\HttpRequest;
use ChomikBox\Http\HttpResponse;
use ChomikBox\Recorder\Recorder;
use ChomikBox\Recorder\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    private function redactor(): Redactor
    {
        return new Redactor((new Credentials('SomeUser', 'secret-pass', 'me@example.org'))->secretsForRedaction());
    }

    public function testKnownSecretsAreReplaced(): void
    {
        $r = $this->redactor();
        $out = $r->redact('<name>someuser</name><passHash>' . md5('secret-pass') . '</passHash> mail ME@example.org pw secret-pass');
        self::assertSame('<name>{{USERNAME}}</name><passHash>{{PASS_HASH}}</passHash> mail {{EMAIL}} pw {{PASSWORD}}', $out);
    }

    public function testLearnedTokenIsReplacedEverywhere(): void
    {
        $r = $this->redactor();
        $r->learn('<a:token>abcd-1234-efgh</a:token>');
        self::assertSame('x {{TOKEN}} ?t={{TOKEN}}&returnUrl=/', $r->redact('x abcd-1234-efgh ?t=abcd-1234-efgh&returnUrl=/'));
    }

    public function testScopedElements(): void
    {
        $r = $this->redactor();
        $upload = '<UploadTokenResult><a:key>0123456789abcdef0123456789abcdef</a:key><a:stamp>639284501532998945</a:stamp></UploadTokenResult>';
        $r->learn($upload);
        self::assertSame('<UploadTokenResult><a:key>{{UPLOAD_TOKEN}}</a:key><a:stamp>{{STAMP}}</a:stamp></UploadTokenResult>', $r->redact($upload));
        // the numeric stamp is caught in a multipart body too
        self::assertStringContainsString("\r\n\r\n{{STAMP}}\r\n", $r->redact("name=\"time\"\r\n\r\n639284501532998945\r\n"));
        // a Download sequence stamp is not a secret
        self::assertSame('<sequence><stamp>1001</stamp></sequence>', $r->redact('<sequence><stamp>1001</stamp></sequence>'));
        // signed URLs in FileEntry
        $dl = '<FileEntry><url>http://s1.chomikuj.pl/File.aspx?e=AAA&amp;pv=2</url></FileEntry>';
        $r->learn($dl);
        self::assertSame('<FileEntry><url>{{SIGNED_URL}}</url></FileEntry>', $r->redact($dl));
        self::assertSame('GET /File.aspx?e={{SIGNED_E}}&pv={{SIGNED_PV}}', $r->redact('GET /File.aspx?e=AAA&pv=2'));
    }

    public function testExplicitPlaceholderIsNotOverwrittenByLearn(): void
    {
        $r = $this->redactor();
        $wrong = md5('not-the-password');
        $r->register($wrong, '{{WRONG_PASS_HASH}}');
        $r->learn("<passHash>$wrong</passHash>");
        self::assertSame('<passHash>{{WRONG_PASS_HASH}}</passHash>', $r->redact("<passHash>$wrong</passHash>"));
        self::assertSame('<passHash>{{PASS_HASH}}</passHash>', $r->redact('<passHash>' . md5('secret-pass') . '</passHash>'));
    }

    public function testFolderPasswordsAreRedacted(): void
    {
        $r = $this->redactor();
        $xml = '<a:folder><passwd>false</passwd><password>_test00</password></a:folder><password i:nil="true"/><password></password>';
        $r->learn($xml);
        self::assertSame('<a:folder><passwd>false</passwd><password>{{FOLDER_PASSWORD}}</password></a:folder><password i:nil="true"/><password></password>', $r->redact($xml));
    }

    public function testPublicValuesStay(): void
    {
        $r = $this->redactor();
        $r->learn('?t=invalid&x=1 <token>NOT-A-REAL-TOKEN</token>');
        self::assertSame('?t=invalid&returnUrl=/ <token>NOT-A-REAL-TOKEN</token> an invalid value',
            $r->redact('?t=invalid&returnUrl=/ <token>NOT-A-REAL-TOKEN</token> an invalid value'));
    }

    public function testCookiesKeepOnlyNames(): void
    {
        $r = $this->redactor();
        self::assertSame('ChomikSession={{COOKIE}}; path=/; HttpOnly', $r->redactHeader('Set-Cookie', 'ChomikSession=abc; path=/; HttpOnly'));
        self::assertSame('a={{COOKIE}}; b={{COOKIE}}', $r->redactHeader('Cookie', 'a=1; b=2'));
    }

    public function testAssertCleanFailsClosed(): void
    {
        $r = $this->redactor();
        $this->expectException(\LogicException::class);
        $r->assertClean('contains SOMEUSER');
    }

    public function testRecorderWritesRedactedFiles(): void
    {
        $dir = sys_get_temp_dir() . '/chomik-rec-' . bin2hex(random_bytes(4));
        $r = $this->redactor();
        $rec = new Recorder($dir, $r);
        $req = new HttpRequest('POST', 'http://box.chomikuj.pl/services/ChomikBoxService.svc', [['SOAPAction', 'x'], ['Content-Type', 'text/xml']],
            '<Auth><name>SomeUser</name></Auth>');
        $body = '<s:Envelope><a:token>tok-123456</a:token><a:name>SomeUser</a:name></s:Envelope>';
        $resp = new HttpResponse(200, 'OK', [['Content-Type', 'text/xml'], ['Set-Cookie', 'S=v; path=/']], $body, $body, 1.0);
        $rec->observe($req, $resp);
        $rec->recordLast('Auth', 'case', 'note for SomeUser');
        $all = '';
        foreach (glob("$dir/Auth/case.*") as $f) {
            $all .= file_get_contents($f);
            unlink($f);
        }
        rmdir("$dir/Auth");
        rmdir($dir);
        self::assertStringNotContainsStringIgnoringCase('someuser', $all);
        self::assertStringNotContainsString('tok-123456', $all);
        self::assertStringContainsString('{{TOKEN}}', $all);
        self::assertStringContainsString('"setCookieNames": [', $all);
    }
}
