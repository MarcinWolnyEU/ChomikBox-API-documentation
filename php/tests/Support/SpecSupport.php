<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Support;

use ChomikBox\ChomikBoxClient;
use ChomikBox\Model\AddFolderResult;
use ChomikBox\Model\AuthResult;
use ChomikBox\Model\CheckEventsResult;
use ChomikBox\Model\DownloadResult;
use ChomikBox\Model\FolderFilesResult;
use ChomikBox\Model\FoldersResult;
use ChomikBox\Model\ModifyFolderResult;
use ChomikBox\Model\RefreshFileUrlResult;
use ChomikBox\Model\Result;
use ChomikBox\Model\SavePlaylistResult;
use ChomikBox\Model\SettingsResult;
use ChomikBox\Model\UploadTokenResult;
use ChomikBox\Spec\OpenApiContract;
use ChomikBox\Upload\Uploader;

/**
 * Shared spec access and the documented mappings used by the Contract suite.
 */
final class SpecSupport
{
    private static ?OpenApiContract $contract = null;

    public static function contract(): OpenApiContract
    {
        return self::$contract ??= new OpenApiContract(OpenApiContract::defaultSpecFile());
    }

    private static ?OpenApiContract $strict = null;

    /** The strict canonical document, loaded explicitly (not via SPEC_FILES). */
    public static function strict(): OpenApiContract
    {
        return self::$strict ??= new OpenApiContract(OpenApiContract::strictSpecFile());
    }

    public static function available(): bool
    {
        return is_file(OpenApiContract::defaultSpecFile());
    }

    /** operationId => client entry point implementing it. */
    public const CLIENT_METHODS = [
        'auth' => [ChomikBoxClient::class, 'auth'],
        'logout' => [ChomikBoxClient::class, 'logout'],
        'checkEvents' => [ChomikBoxClient::class, 'checkEvents'],
        'boxEvents' => [ChomikBoxClient::class, 'boxEvents'],
        'getSettings' => [ChomikBoxClient::class, 'getSettings'],
        'setSettings' => [ChomikBoxClient::class, 'setSettings'],
        'folders' => [ChomikBoxClient::class, 'folders'],
        'folderFiles' => [ChomikBoxClient::class, 'folderFiles'],
        'addFolder' => [ChomikBoxClient::class, 'addFolder'],
        'removeFolder' => [ChomikBoxClient::class, 'removeFolder'],
        'renameFolder' => [ChomikBoxClient::class, 'renameFolder'],
        'moveFolder' => [ChomikBoxClient::class, 'moveFolder'],
        'modifyFolder' => [ChomikBoxClient::class, 'modifyFolder'],
        'download' => [ChomikBoxClient::class, 'download'],
        'uploadToken' => [ChomikBoxClient::class, 'uploadToken'],
        'refreshFileUrl' => [ChomikBoxClient::class, 'refreshFileUrl'],
        'savePlaylist' => [ChomikBoxClient::class, 'savePlaylist'],
        'uploadFile' => [Uploader::class, 'upload'],
        'resumeCheck' => [Uploader::class, 'resumeCheck'],
        'downloadFile' => [ChomikBoxClient::class, 'fetchFile'],
        'loginFromBox' => [ChomikBoxClient::class, 'webLoginUrl'],
    ];

    /** operationId => why the client deliberately does not implement it. */
    public const SKIPPED = [
        'sessionBegin' => 'Separate session server (session.box.chomikuj.pl) with no DNS record; only used when session-enabled=true (default false). Not implemented so that credentials are never sent to an unverified host.',
        'probeSessionPath' => 'Probe of /session on the main host (302 to Error404); documents the missing session server, not a client feature.',
        'headFile' => 'The file server does not support HEAD (302 to Error404); the client never sends HEAD.',
        'openPublicFolderPage' => 'Probe: anonymous GET of a public folder page on chomikuj.pl, used to look for effects of ModifyFolder fields; the client never requests it.',
        'openPublicFilePage' => 'Probe: anonymous GET of a public file page on chomikuj.pl, used to look for effects of ModifyFolder fields; the client never requests it.',
        'loginFromBoxLegacy' => 'Configurator default /box/LoginFromBox, 404/error today; the client uses loginFromBox (webLoginUrl).',
        'openRegistrationPage' => 'Static web page linked from the login window, opened in a browser.',
        'openLostPasswordPage' => 'Static web page linked from the login window, opened in a browser.',
        'imageRedirect' => 'Web preview URL the downloader converts to a file id; files are fetched via Download/RefreshFileUrl + fetchFile.',
        'musicFileLegacy' => 'ChomikBox 1.x cache identifier, never requested by 2.0.8.2 and gone live.',
        'sendCrashReport' => 'Crash reporter (crashsender.exe); out of scope and it would upload private logs.',
        'getUpdateRepository' => 'Updater channel, dead today (404); out of scope.',
        'getUpdatesDirectory' => 'Updater channel, dead today (403); out of scope.',
        'openBetaRepository' => 'Updater channel, dead today (404); out of scope.',
        'getServiceMetadataPage' => 'WCF metadata page (documents that no WSDL is published); not an API call.',
        'soapEndpoint' => 'Generic SOAP endpoint documenting WCF faults (ActionNotSupported, 415, ...); the client uses the typed operations.',
        'soapRestStylePath' => 'Documents that .svc/<Op> paths fail (DestinationUnreachable); the client never uses them.',
    ];

    /**
     * SOAP operationId => [client method called, Live test methods that call it].
     * Every live response is additionally validated against the spec by the Live suite.
     */
    public const LIVE_COVERAGE = [
        'auth' => ['auth', ['testLogoutInvalidatesOnlyThatSession']],
        'logout' => ['logout', ['testLogoutInvalidatesOnlyThatSession', 'testBadTokenIsReportedByEveryOperation']],
        'checkEvents' => ['checkEvents', ['testSessionAndPolling', 'testDownloadRoundTripWithOwnAgreement']],
        'boxEvents' => ['boxEvents', ['testBoxEventsAndDeserializationFault']],
        'getSettings' => ['getSettings', ['testSettingsReadAndIdenticalWriteBack']],
        'setSettings' => ['setSettings', ['testSettingsReadAndIdenticalWriteBack']],
        'folders' => ['folders', ['testFolderLifecycle']],
        'folderFiles' => ['folderFiles', ['testFolderFilesListsAudioOnly', 'testFolderFilesListsUploadedAudioAndStreamUrlWorks']],
        'addFolder' => ['addFolder', ['testFolderLifecycle']],
        'removeFolder' => ['removeFolder', ['testFolderLifecycle']],
        'renameFolder' => ['renameFolder', ['testFolderLifecycle']],
        'moveFolder' => ['moveFolder', ['testFolderLifecycle']],
        'modifyFolder' => ['modifyFolder', ['testFolderLifecycle']],
        'download' => ['download', ['testDownloadRoundTripWithOwnAgreement', 'testRefreshFileUrlAndFolderListing']],
        'uploadToken' => ['uploadToken', ['testUploadWithForgedKeyIsRejected']],
        'refreshFileUrl' => ['refreshFileUrl', ['testRefreshFileUrlAndFolderListing']],
        'savePlaylist' => ['savePlaylist', ['testBadTokenIsReportedByEveryOperation']],
    ];

    /** SOAP operations without a Live test, with the reason. */
    public const NO_LIVE_TEST = [
        'sessionBegin' => 'Not implemented (see SKIPPED); its host does not resolve.',
    ];

    /** Notes on Live coverage limits that are documented rather than tested. */
    public const LIVE_COVERAGE_NOTES = [
        'savePlaylist' => 'The Live suite only exercises the BadToken path: a successful call writes outside the sandbox (Playlisty/<name>). The success case was captured once by tools/capture-audio.php under a full-tree snapshot guard.',
    ];

    /** Typed result model per SOAP operation (others parse as the base Result). */
    public const RESULT_MODELS = [
        'auth' => AuthResult::class,
        'folders' => FoldersResult::class,
        'addFolder' => AddFolderResult::class,
        'modifyFolder' => ModifyFolderResult::class,
        'folderFiles' => FolderFilesResult::class,
        'download' => DownloadResult::class,
        'refreshFileUrl' => RefreshFileUrlResult::class,
        'uploadToken' => UploadTokenResult::class,
        'savePlaylist' => SavePlaylistResult::class,
        'getSettings' => SettingsResult::class,
        'checkEvents' => CheckEventsResult::class,
    ];

    /** @return class-string<Result> */
    public static function resultModel(string $operationId): string
    {
        return self::RESULT_MODELS[$operationId] ?? Result::class;
    }
}
