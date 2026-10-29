<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Offline;

use ChomikBox\Soap\Envelope;
use ChomikBox\Soap\RawXml;
use ChomikBox\Soap\SoapList;
use ChomikBox\Soap\SoapStruct;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    public function testAuthEnvelopeMatchesVerifiedShape(): void
    {
        $xml = Envelope::build('Auth', [
            'name' => 'user',
            'passHash' => 'd41d8cd98f00b204e9800998ecf8427e',
            'ver' => 4,
            'client' => ['name' => 'chomikbox', 'version' => '2.0.8.2'],
        ]);
        self::assertSame(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            . '<Auth xmlns="http://chomikuj.pl/"><name>user</name><passHash>d41d8cd98f00b204e9800998ecf8427e</passHash><ver>4</ver>'
            . '<client><name>chomikbox</name><version>2.0.8.2</version></client></Auth></s:Body></s:Envelope>',
            $xml,
        );
    }

    public function testNilBooleanAndEscaping(): void
    {
        $xml = Envelope::build('ModifyFolder', ['token' => 'a<b&c', 'name' => null, 'hidden' => true, 'adult' => false]);
        self::assertStringContainsString('xmlns:i="http://www.w3.org/2001/XMLSchema-instance"', $xml);
        self::assertStringContainsString('<token>a&lt;b&amp;c</token><name i:nil="true"/><hidden>true</hidden><adult>false</adult>', $xml);
    }

    public function testListsAreRepeatedItemElements(): void
    {
        $xml = Envelope::build('Download', [
            'list' => new SoapList('DownloadReqEntry', [
                ['id' => '1'],
                ['id' => '2', 'agreementInfo' => new SoapList('AgreementInfo', [['name' => 'own', 'cost' => 0]])],
            ]),
        ], xmlDeclaration: false);
        self::assertStringContainsString(
            '<list><DownloadReqEntry><id>1</id></DownloadReqEntry><DownloadReqEntry><id>2</id>'
            . '<agreementInfo><AgreementInfo><name>own</name><cost>0</cost></AgreementInfo></agreementInfo></DownloadReqEntry></list>',
            $xml,
        );
        self::assertStringStartsWith('<s:Envelope', $xml);
    }

    public function testEmptyListAndRawXml(): void
    {
        $xml = Envelope::build('BoxEvents', ['events' => new SoapList('Event', []), 'x' => new RawXml('<y/>')]);
        self::assertStringContainsString('<events></events><x><y/></x>', $xml);
    }

    public function testForeignMemberNamespaceGetsPrefix(): void
    {
        $xml = Envelope::build('Auth', ['client' => new SoapStruct(['name' => 'n'], Envelope::CONTRACT_NS)]);
        self::assertStringContainsString('xmlns:a="http://chomikuj.pl"', $xml);
        self::assertStringContainsString('<client><a:name>n</a:name></client>', $xml);
    }

    public function testEncodingStyleAndHeaderOptions(): void
    {
        $xml = Envelope::build('CheckEvents', ['token' => 't'], encodingStyle: true, header: ['Token' => 'h']);
        self::assertStringContainsString('s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"', $xml);
        self::assertStringContainsString('<s:Header><Token xmlns="http://chomikuj.pl/">h</Token></s:Header>', $xml);
    }

    public function testActionUri(): void
    {
        self::assertSame('http://chomikuj.pl/IChomikBoxService/Folders', Envelope::action('Folders'));
    }

    public function testEnvelopeIsWellFormed(): void
    {
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML(Envelope::build('Folders', ['token' => 't', 'hamsterId' => 1, 'folderId' => 0, 'depth' => 0])));
        $op = $doc->getElementsByTagNameNS('http://chomikuj.pl/', 'Folders')->item(0);
        self::assertNotNull($op);
        $names = [];
        foreach ($op->childNodes as $n) {
            $names[] = [$n->namespaceURI, $n->localName];
        }
        self::assertSame([
            ['http://chomikuj.pl/', 'token'], ['http://chomikuj.pl/', 'hamsterId'],
            ['http://chomikuj.pl/', 'folderId'], ['http://chomikuj.pl/', 'depth'],
        ], $names);
    }
}
