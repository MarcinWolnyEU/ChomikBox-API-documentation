<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Offline;

use ChomikBox\Model\UploadTokenResult;
use ChomikBox\Soap\XmlNode;
use ChomikBox\Upload\ChbMultipart;
use ChomikBox\Upload\RespXml;
use ChomikBox\Upload\Uploader;
use ChomikBox\ChomikBoxClient;
use ChomikBox\Soap\SoapClient;
use ChomikBox\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class MultipartTest extends TestCase
{
    public function testPartBuilderMirrorsClient(): void
    {
        self::assertSame("--!CHB\r\nname=\"chomik_id\"\r\nContent-Type: text/plain\r\n\r\n42\r\n", ChbMultipart::part('chomik_id', '42'));
        self::assertSame("--!CHB\r\nname=\"file\"; filename=\"a.txt\"\r\n\r\n", ChbMultipart::part('file', '', 'a.txt'));
        self::assertSame("--!CHB\r\nname=\"thumb\"\r\nContent-Type: image/jpeg\r\n\r\nJPG\r\n", ChbMultipart::part('thumb', 'JPG', '', 'image/jpeg'));
        self::assertSame("\r\n--!CHB--\r\n", ChbMultipart::closing());
        // the real client appends the UploadToken stamp to the boundary
        self::assertSame("--!CHB77\r\nname=\"key\"\r\nContent-Type: text/plain\r\n\r\nk\r\n", ChbMultipart::part('key', 'k', '', '', '77'));
        self::assertSame("\r\n--!CHB77--\r\n", ChbMultipart::closing('77'));
        self::assertSame('multipart/mixed; boundary=!CHB77', (new ChbMultipart('77'))->contentType());
    }

    public function testFullBodyLayout(): void
    {
        $body = (new ChbMultipart())->field('chomik_id', 1)->field('key', 'k')->file('x.bin', "\x00\x01DATA")->body();
        self::assertSame(
            "--!CHB\r\nname=\"chomik_id\"\r\nContent-Type: text/plain\r\n\r\n1\r\n"
            . "--!CHB\r\nname=\"key\"\r\nContent-Type: text/plain\r\n\r\nk\r\n"
            . "--!CHB\r\nname=\"file\"; filename=\"x.bin\"\r\n\r\n\x00\x01DATA\r\n--!CHB--\r\n",
            $body,
        );
    }

    public function testFilePartMustBeLast(): void
    {
        $this->expectException(\LogicException::class);
        (new ChbMultipart())->file('a', 'b')->field('x', 'y');
    }

    public function testUploaderBuildsClientFieldOrder(): void
    {
        $http = new FakeTransport();
        $client = new ChomikBoxClient(new SoapClient($http), $http);
        $node = new XmlNode((new \DOMDocument())->createElement('UploadTokenResult'));
        $token = new UploadTokenResult('Ok', null, $node);
        $token->key = 'KEY';
        $token->stamp = '123';
        $token->server = 's1.chomikuj.pl:8084';
        $token->locale = 'PL';
        $req = (new Uploader($client))->buildRequest($token, 42, 7, 'pic.png', 'PNGDATA', [
            'image' => ['width' => 64, 'height' => 48, 'thWidth' => 64, 'thHeight' => 48, 'thumb' => 'JPG'],
        ]);
        self::assertSame('http://s1.chomikuj.pl:8084/file/', $req->url);
        self::assertSame('multipart/mixed; boundary=!CHB123', $req->header('Content-Type'));
        preg_match_all('/(?<!file)name="([^"]+)"/', $req->body, $m);
        self::assertSame(['chomik_id', 'folder_id', 'key', 'time', 'width', 'height', 'th_width', 'th_height', 'thumb', 'client', 'locale', 'file'], $m[1]);
        self::assertStringStartsWith("--!CHB123\r\nname=\"chomik_id\"", $req->body);
        self::assertStringContainsString("name=\"client\"\r\nContent-Type: text/plain\r\n\r\nChomikBox-2.0.8.2\r\n", $req->body);
        self::assertStringEndsWith("filename=\"pic.png\"\r\n\r\nPNGDATA\r\n--!CHB123--\r\n", $req->body);
    }

    public function testResumeFromSendsRemainderOnly(): void
    {
        $http = new FakeTransport();
        $client = new ChomikBoxClient(new SoapClient($http), $http);
        $token = new UploadTokenResult('Ok', null, new XmlNode((new \DOMDocument())->createElement('x')));
        $token->key = 'K';
        $token->stamp = '1';
        $token->server = 'h:1';
        $req = (new Uploader($client))->buildRequest($token, 1, 2, 'f', 'ABCDEF', ['resumeFrom' => 4]);
        self::assertStringContainsString("name=\"resume_from\"\r\nContent-Type: text/plain\r\n\r\n4\r\n", $req->body);
        self::assertStringEndsWith("\r\n\r\nEF\r\n--!CHB1--\r\n", $req->body);
    }

    public function testRespXmlParsing(): void
    {
        $ok = RespXml::parse("<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n<resp res=\"1\" fileid=\"10832619290\"/>");
        self::assertTrue($ok->isOk());
        self::assertSame('10832619290', $ok->attr('fileid'));
        $resume = RespXml::parse('<resp file_size="4096" skipThumbnails="true" res="1"/>');
        self::assertSame('4096', $resume->attr('file_size'));
        self::assertSame(-31, RespXml::parse('<resp res="-31"/>')->res);
        self::assertSame(PHP_INT_MIN, RespXml::parse('not xml')->res);
    }
}
