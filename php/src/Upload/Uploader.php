<?php

declare(strict_types=1);

namespace ChomikBox\Upload;

use ChomikBox\ChomikBoxClient;
use ChomikBox\Http\HttpRequest;
use ChomikBox\Model\UploadTokenResult;

/**
 * File upload as done by NetProtoBox::sendUploadToken / sendUploadFile:
 *
 *  1. SOAP UploadToken(token, folderId, fileName) -> key, stamp, server, locale
 *  2. optional GET http://{server}/resume/check/?key={key}  -> <resp res=".." file_size=".."/>
 *  3. POST http://{server}/file/  (Content-Type: multipart/mixed; boundary=!CHB<stamp>)
 *     parts in order: chomik_id, folder_id, key, time(=stamp), [resume_from],
 *     [width, height, th_width, th_height, thumb (image/jpeg), medthumb (image/jpeg)],
 *     client ("ChomikBox-2.0.8.2"), [locale], file (last; raw bytes, closing boundary)
 *     -> <resp res="1" fileid="..."/>   errors: <resp res="-42" errorMessage="Bad token"/>
 */
final class Uploader
{
    /** "%1-%2" in the client: application title + version (inferred; not validated by the server). */
    public const CLIENT_FIELD = 'ChomikBox-' . ChomikBoxClient::CLIENT_VERSION;

    public function __construct(private readonly ChomikBoxClient $client)
    {
    }

    /**
     * @param array{
     *   resumeFrom?: int,
     *   image?: array{width:int, height:int, thWidth:int, thHeight:int, thumb:string, medthumb?:string},
     *   locale?: string,
     *   boundarySuffix?: string,
     *   clientField?: string,
     * } $options
     */
    public function upload(int $folderId, string $fileName, string $data, array $options = []): UploadResult
    {
        $token = $this->client->uploadToken($folderId, $fileName);
        if (!$token->isOk() || $token->server === null) {
            return new UploadResult($token, null, null, null);
        }
        $request = $this->buildRequest($token, (int) $this->client->hamsterId(), $folderId, $fileName, $data, $options);
        $response = $this->client->http()->send($request);
        return new UploadResult($token, $request, $response, RespXml::parse($response->body));
    }

    /** @param array<string, mixed> $options see upload() */
    public function buildRequest(
        UploadTokenResult $token,
        int $hamsterId,
        int $folderId,
        string $fileName,
        string $data,
        array $options = [],
    ): HttpRequest {
        $mp = (new ChbMultipart($options['boundarySuffix'] ?? (string) $token->stamp))
            ->field('chomik_id', $hamsterId)
            ->field('folder_id', $folderId)
            ->field('key', (string) $token->key)
            ->field('time', (string) $token->stamp);
        if (($options['resumeFrom'] ?? 0) > 0) {
            $mp->field('resume_from', (int) $options['resumeFrom']);
            $data = substr($data, (int) $options['resumeFrom']);
        }
        if (isset($options['image'])) {
            $img = $options['image'];
            $mp->field('width', $img['width'])
                ->field('height', $img['height'])
                ->field('th_width', $img['thWidth'])
                ->field('th_height', $img['thHeight'])
                ->field('thumb', $img['thumb'], 'image/jpeg');
            if (isset($img['medthumb'])) {
                $mp->field('medthumb', $img['medthumb'], 'image/jpeg');
            }
        }
        $mp->field('client', $options['clientField'] ?? self::CLIENT_FIELD);
        $locale = $options['locale'] ?? $token->locale;
        if ($locale !== null && $locale !== '') {
            $mp->field('locale', $locale);
        }
        $mp->file($fileName, $data);

        return new HttpRequest(
            'POST',
            'http://' . $token->server . '/file/',
            [
                ['Content-Type', $mp->contentType()],
                ['User-Agent', 'Mozilla/5.0'],
                ['Accept-Encoding', 'gzip'],
                ['Connection', 'Keep-Alive'],
            ],
            $mp->body(),
        );
    }

    /** GET http://{server}/resume/check/?key={key} */
    public function resumeCheck(string $server, #[\SensitiveParameter] string $key): RespXml
    {
        $response = $this->client->http()->send(new HttpRequest(
            'GET',
            'http://' . $server . '/resume/check/?key=' . rawurlencode($key),
            [['User-Agent', 'Mozilla/5.0'], ['Accept-Encoding', 'gzip'], ['Connection', 'Keep-Alive']],
        ));
        return RespXml::parse($response->body);
    }
}
