<?php

declare(strict_types=1);

namespace ChomikBox;

use ChomikBox\Http\CurlTransport;
use ChomikBox\Http\HttpRequest;
use ChomikBox\Http\HttpResponse;
use ChomikBox\Http\Transport;
use ChomikBox\Model\AddFolderResult;
use ChomikBox\Model\AuthResult;
use ChomikBox\Model\CheckEventsResult;
use ChomikBox\Model\DownloadRequest;
use ChomikBox\Model\DownloadResult;
use ChomikBox\Model\FolderChanges;
use ChomikBox\Model\FolderFilesResult;
use ChomikBox\Model\FoldersResult;
use ChomikBox\Model\MessageSequence;
use ChomikBox\Model\ModifyFolderResult;
use ChomikBox\Model\RefreshFileUrlResult;
use ChomikBox\Model\Result;
use ChomikBox\Model\SavePlaylistResult;
use ChomikBox\Model\SettingsResult;
use ChomikBox\Model\UploadTokenResult;
use ChomikBox\Recorder\Recorder;
use ChomikBox\Soap\SoapCall;
use ChomikBox\Soap\SoapClient;
use ChomikBox\Soap\SoapList;
use ChomikBox\Soap\XmlNode;

/**
 * High-level client for ChomikBoxService.svc (the ChomikBox 2.0.8.2 protocol).
 *
 * All operations return a typed Result; a non-Ok <status> is NOT an exception
 * (check ->isOk() / ->status), because many statuses are normal answers.
 * SOAP faults (HTTP 500) are thrown as Soap\SoapFault.
 *
 * Parameter names, order and types below were verified against the live
 * service (WCF is order-sensitive: out-of-order parameters are silently
 * ignored).
 */
final class ChomikBoxClient
{
    public const CLIENT_NAME = 'chomikbox';
    public const CLIENT_VERSION = '2.0.8.2';
    public const PROTOCOL_VERSION = 4;
    public const DISPOSITION_DOWNLOAD = 'download';
    public const DISPOSITION_AUDIO = 'player.audio';
    /** Verified live; the configurator default http://%1/box/LoginFromBox is 404 today. */
    public const WEB_LOGIN_URL = 'http://chomikuj.pl/chomik/chomikbox/LoginFromBox';

    private ?string $token = null;
    private ?int $hamsterId = null;
    private ?SoapCall $lastCall = null;

    public function __construct(
        private readonly SoapClient $soap,
        private readonly Transport $http,
    ) {
    }

    /**
     * Defaults reproduce the real client's wire format (gzip body, encodingStyle, 0/1 booleans).
     *
     * @param array{endpoint?:string, gzipRequest?:bool, acceptGzip?:bool, encodingStyle?:bool, numericBooleans?:bool, delay?:float, timeout?:int} $options
     */
    public static function create(array $options = [], ?Recorder $recorder = null): self
    {
        $http = new CurlTransport($options['timeout'] ?? 30, 15, $options['delay'] ?? 0.0);
        if ($recorder !== null) {
            $http->addObserver([$recorder, 'observe']);
        }
        $soap = new SoapClient(
            $http,
            $options['endpoint'] ?? SoapClient::DEFAULT_ENDPOINT,
            $options['gzipRequest'] ?? true,
            $options['acceptGzip'] ?? true,
            'Mozilla/5.0',
            $options['encodingStyle'] ?? true,
            $options['numericBooleans'] ?? true,
        );
        return new self($soap, $http);
    }

    public function soap(): SoapClient
    {
        return $this->soap;
    }

    public function http(): Transport
    {
        return $this->http;
    }

    public function lastCall(): ?SoapCall
    {
        return $this->lastCall;
    }

    public function token(): ?string
    {
        return $this->token;
    }

    public function hamsterId(): ?int
    {
        return $this->hamsterId;
    }

    public function setSession(#[\SensitiveParameter] string $token, int $hamsterId): void
    {
        $this->token = $token;
        $this->hamsterId = $hamsterId;
    }

    public function isLoggedIn(): bool
    {
        return $this->token !== null;
    }

    // ---------------------------------------------------------------- session

    /** Auth(name, passHash, ver, client{name, version}). Stores the session on status Ok. */
    public function auth(Credentials $credentials): AuthResult
    {
        return $this->authRaw($credentials->username, $credentials->passHash());
    }

    public function authRaw(string $name, #[\SensitiveParameter] string $passHash, int $ver = self::PROTOCOL_VERSION): AuthResult
    {
        $r = AuthResult::fromNode($this->invoke('Auth', [
            'name' => $name,
            'passHash' => $passHash,
            'ver' => $ver,
            'client' => ['name' => self::CLIENT_NAME, 'version' => self::CLIENT_VERSION],
        ]));
        if ($r->isOk() && $r->token !== null) {
            $this->token = $r->token;
            $this->hamsterId = $r->hamsterId;
        }
        return $r;
    }

    /** Logout(token[, locale]). The real client sends only the token. Clears the local session on Ok. */
    public function logout(?string $locale = null): Result
    {
        $params = ['token' => $this->token];
        if ($locale !== null) {
            $params['locale'] = $locale;
        }
        $r = Result::fromNode($this->invoke('Logout', $params));
        if ($r->isOk()) {
            $this->token = null;
        }
        return $r;
    }

    // ---------------------------------------------------------------- folders

    /**
     * Folders(token, hamsterId, folderId, depth).
     * depth: 0 = whole subtree, 1 = the folder only, 2 = folder + children, ...
     */
    public function folders(int $folderId = 0, int $depth = 0, ?int $hamsterId = null): FoldersResult
    {
        return FoldersResult::fromNode($this->invoke('Folders', [
            'token' => $this->token,
            'hamsterId' => $hamsterId ?? $this->hamsterId ?? 0,
            'folderId' => $folderId,
            'depth' => $depth,
        ]));
    }

    /** AddFolder(token, newFolderId = parent folder id, name). */
    public function addFolder(int $parentId, string $name): AddFolderResult
    {
        return AddFolderResult::fromNode($this->invoke('AddFolder', [
            'token' => $this->token,
            'newFolderId' => $parentId,
            'name' => $name,
        ]));
    }

    /** RemoveFolder(token, folderId, force). */
    public function removeFolder(int $folderId, bool $force = false): Result
    {
        return Result::fromNode($this->invoke('RemoveFolder', [
            'token' => $this->token,
            'folderId' => $folderId,
            'force' => $force,
        ]));
    }

    /** RenameFolder(token, folderId, name). */
    public function renameFolder(int $folderId, string $name): Result
    {
        return Result::fromNode($this->invoke('RenameFolder', [
            'token' => $this->token,
            'folderId' => $folderId,
            'name' => $name,
        ]));
    }

    /** MoveFolder(token, folderId, newFolderId = new parent id). */
    public function moveFolder(int $folderId, int $newParentId): Result
    {
        return Result::fromNode($this->invoke('MoveFolder', [
            'token' => $this->token,
            'folderId' => $folderId,
            'newFolderId' => $newParentId,
        ]));
    }

    /**
     * ModifyFolder(token, folderId, [name], [password], [hidden], [adult], [freePoints], [freeTransfer],
     * [publicUpload], [description], [view]). Only the changed fields are sent (omitted = unchanged),
     * as the real client does. Note: the real client calls the last flag "public", which the
     * service does not know (it is silently ignored); the service parameter is publicUpload.
     */
    public function modifyFolder(int $folderId, FolderChanges $changes): ModifyFolderResult
    {
        return ModifyFolderResult::fromNode($this->invoke('ModifyFolder', [
            'token' => $this->token,
            'folderId' => $folderId,
        ] + $changes->toSoap()));
    }

    // ---------------------------------------------------------------- files

    /** FolderFiles(token, hamsterId, folderId, disposition); lists audio files for the player as EntryInfo{id, name, type, size}. */
    public function folderFiles(int $folderId, string $disposition = self::DISPOSITION_AUDIO, ?int $hamsterId = null): FolderFilesResult
    {
        return FolderFilesResult::fromNode($this->invoke('FolderFiles', [
            'token' => $this->token,
            'hamsterId' => $hamsterId ?? $this->hamsterId ?? 0,
            'folderId' => $folderId,
            'disposition' => $disposition,
        ]));
    }

    /**
     * Download(token, sequence{stamp,part,count}, disposition, list of DownloadReqEntry{id, agreementInfo[]}).
     *
     * @param list<DownloadRequest> $entries
     */
    public function download(array $entries, string $disposition = self::DISPOSITION_DOWNLOAD, ?MessageSequence $sequence = null): DownloadResult
    {
        $items = [];
        foreach ($entries as $e) {
            $item = ['id' => $e->id];
            if ($e->agreements !== []) {
                $item['agreementInfo'] = new SoapList('AgreementInfo', array_map(static fn ($a) => $a->toSoap(), $e->agreements));
            }
            $items[] = $item;
        }
        return DownloadResult::fromNode($this->invoke('Download', [
            'token' => $this->token,
            'sequence' => ($sequence ?? MessageSequence::single())->toSoap(),
            'disposition' => $disposition,
            'list' => new SoapList('DownloadReqEntry', $items),
        ]));
    }

    /** RefreshFileUrl(token, id, realId, disposition): new signed URL for a file. */
    public function refreshFileUrl(int|string $id, int $realId = 0, string $disposition = self::DISPOSITION_DOWNLOAD): RefreshFileUrlResult
    {
        return RefreshFileUrlResult::fromNode($this->invoke('RefreshFileUrl', [
            'token' => $this->token,
            'id' => (string) $id,
            'realId' => $realId,
            'disposition' => $disposition,
        ]));
    }

    /** UploadToken(token, folderId, fileName): key/stamp/server for the HTTP upload (see Upload\Uploader). */
    public function uploadToken(int $folderId, string $fileName): UploadTokenResult
    {
        return UploadTokenResult::fromNode($this->invoke('UploadToken', [
            'token' => $this->token,
            'folderId' => $folderId,
            'fileName' => $fileName,
        ]));
    }

    /**
     * SavePlaylist(token, [sequence], name, list of PlayListItem{id, realId}).
     * The real client sends token, name, list (no sequence); per item one of
     * id/realId is real and the other -1.
     * Verified live: creates an ordinary folder named $name inside the root
     * folder "Playlisty" (created at the root if missing) and COPIES the files
     * into it (new file ids). There is no parent parameter.
     *
     * @param list<array{id:int|string, realId:int|string}> $items
     */
    public function savePlaylist(string $name, array $items, ?MessageSequence $sequence = null): SavePlaylistResult
    {
        $params = ['token' => $this->token];
        if ($sequence !== null) {
            $params['sequence'] = $sequence->toSoap();
        }
        $params['name'] = $name;
        $params['list'] = new SoapList('PlayListItem', array_map(
            static fn ($i) => ['id' => (string) $i['id'], 'realId' => (string) $i['realId']],
            $items,
        ));
        return SavePlaylistResult::fromNode($this->invoke('SavePlaylist', $params));
    }

    // ---------------------------------------------------------------- settings & events

    /** GetSettings(token). */
    public function getSettings(): SettingsResult
    {
        return SettingsResult::fromNode($this->invoke('GetSettings', ['token' => $this->token]));
    }

    /**
     * SetSettings(token, settings{downloadByBox}).
     *
     * @param array<string, bool|string> $settings
     */
    public function setSettings(array $settings): Result
    {
        return Result::fromNode($this->invoke('SetSettings', ['token' => $this->token, 'settings' => $settings]));
    }

    /**
     * CheckEvents(token, stats{isUploading, isPlaying, isDownloading, panelSelectedTab, animation}).
     *
     * @param array{isUploading?:bool, isPlaying?:bool, isDownloading?:bool, panelSelectedTab?:int, animation?:int}|null $stats
     */
    public function checkEvents(?array $stats = null): CheckEventsResult
    {
        $params = ['token' => $this->token];
        if ($stats !== null) {
            $params['stats'] = [
                'isUploading' => $stats['isUploading'] ?? false,
                'isPlaying' => $stats['isPlaying'] ?? false,
                'isDownloading' => $stats['isDownloading'] ?? false,
                'panelSelectedTab' => $stats['panelSelectedTab'] ?? 0,
                'animation' => $stats['animation'] ?? 0,
            ];
        }
        return CheckEventsResult::fromNode($this->invoke('CheckEvents', $params));
    }

    /**
     * BoxEvents(token, timestamp, events of Event{name, value}): GUI usage statistics
     * (disabled by default in the real client, which sends timestamp 0).
     *
     * @param array<string, string> $events name => value
     */
    public function boxEvents(array $events, string $timestamp = '0'): Result
    {
        $list = [];
        foreach ($events as $name => $value) {
            $list[] = ['name' => (string) $name, 'value' => $value];
        }
        return Result::fromNode($this->invoke('BoxEvents', [
            'token' => $this->token,
            'timestamp' => $timestamp,
            'events' => new SoapList('Event', $list),
        ]));
    }

    // ---------------------------------------------------------------- plain HTTP helpers

    /**
     * Web auto-login URL the client opens in a browser (web-box-service-url of the
     * chomikuj.pl brand config): 302 to $returnUrl with a ChomikSession cookie.
     */
    public function webLoginUrl(string $returnUrl = '/'): string
    {
        if ($this->token === null) {
            throw new \LogicException('webLoginUrl() needs a session token (call auth() first)');
        }
        return self::WEB_LOGIN_URL . '?t=' . rawurlencode($this->token) . '&returnUrl=' . rawurlencode($returnUrl);
    }

    /**
     * GET of a signed file URL from Download/RefreshFileUrl (http://sNNNN.chomikuj.pl/File.aspx?e=..&pv=..).
     * $player = true sends the music player's headers (Icy-MetaData: 1, empty Accept-Encoding).
     */
    public function fetchFile(string $url, ?string $range = null, bool $player = false): HttpResponse
    {
        $headers = [['User-Agent', 'Mozilla/5.0']];
        $headers[] = $player ? ['Accept-Encoding', ' '] : ['Accept-Encoding', 'gzip'];
        if ($player) {
            $headers[] = ['Icy-MetaData', '1'];
        }
        if ($range !== null) {
            $headers[] = ['Range', $range];
        }
        return $this->http->send(new HttpRequest('GET', $url, $headers));
    }

    // ---------------------------------------------------------------- plumbing

    /** Raw call for experiments; also updates lastCall(). */
    public function call(string $method, array $params, array $options = []): SoapCall
    {
        return $this->lastCall = $this->soap->call($method, $params, $options);
    }

    private function invoke(string $method, array $params): XmlNode
    {
        return $this->call($method, $params)->result();
    }
}
