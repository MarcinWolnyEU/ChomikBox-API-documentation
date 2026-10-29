<?php

declare(strict_types=1);

namespace ChomikBox\Tools;

use ChomikBox\ChomikBoxClient;
use ChomikBox\Credentials;
use ChomikBox\Http\HttpRequest;
use ChomikBox\Http\HttpResponse;
use ChomikBox\Model\FoldersResult;
use ChomikBox\Recorder\Recorder;
use ChomikBox\Recorder\Redactor;
use ChomikBox\Soap\SoapFault;
use ChomikBox\Upload\Uploader;

/**
 * Shared state and helpers for tools/capture.php.
 */
final class CaptureSession
{
    public const SANDBOX_PREFIX = '_chomikbox_api_test';
    public const FAKE_TOKEN = 'NOT-A-REAL-TOKEN';

    public readonly Credentials $cred;
    public readonly Redactor $redactor;
    public readonly Recorder $recorder;
    public readonly ChomikBoxClient $client;
    public readonly Uploader $uploader;
    public int $sandboxId = 0;
    public string $sandboxName = '';
    /** @var array<string, int> */
    public array $counts = ['recorded' => 0, 'failed' => 0];

    public function __construct(string $examplesDir, float $delay = 0.8)
    {
        $this->cred = Credentials::fromDirectory();
        $this->redactor = new Redactor($this->cred->secretsForRedaction());
        $this->recorder = new Recorder($examplesDir, $this->redactor);
        $this->client = ChomikBoxClient::create(['delay' => $delay], $this->recorder);
        $this->uploader = new Uploader($this->client);
    }

    public function log(string $line): void
    {
        fwrite(STDOUT, $this->redactor->redact($line) . "\n");
    }

    /**
     * Runs $fn (which performs exactly the exchange to record as its last
     * HTTP call), then records the last exchange. SOAP faults are expected
     * outcomes for some cases and are recorded too.
     */
    public function step(
        string $op,
        string $case,
        string $note,
        callable $fn,
        array $extra = [],
        string $bodyMode = Recorder::BODY_FULL,
        ?\Closure $transform = null,
    ): mixed {
        $result = null;
        try {
            $result = $fn();
        } catch (SoapFault $f) {
            $result = $f;
        } catch (\Throwable $e) {
            $this->counts['failed']++;
            $this->log(sprintf('  !! %s/%s failed: %s', $op, $case, $e->getMessage()));
            return null;
        }
        if (is_array($result) && isset($result['__extra'])) {
            $extra += $result['__extra'];
        }
        $this->recorder->recordLast($op, $case, $note, $extra, $bodyMode, $transform);
        $this->counts['recorded']++;
        $resp = $this->recorder->lastResponse();
        $status = $this->client->lastCall()?->response === $resp ? ($this->client->lastCall()?->status() ?? '') : '';
        $fault = $result instanceof SoapFault ? ' fault=' . $result->faultCode : '';
        $this->log(sprintf('  %-16s %-36s HTTP %d %s%s', $op, $case, $resp?->status ?? 0, $status, $fault));
        return $result;
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $h = [['User-Agent', 'Mozilla/5.0'], ['Accept-Encoding', 'gzip'], ['Connection', 'Keep-Alive']];
        foreach ($headers as $name => $value) {
            $h[] = [$name, $value];
        }
        return $this->client->http()->send(new HttpRequest('GET', $url, $h));
    }

    public function sandboxTree(): FoldersResult
    {
        return $this->client->folders($this->sandboxId, 0);
    }

    /** Hard guard before any destructive call: the folder must be the sandbox or inside it. */
    public function assertInSandbox(int $folderId): void
    {
        if ($this->sandboxId <= 0 || $folderId <= 0) {
            throw new \LogicException("Refusing to touch folder $folderId: outside sandbox");
        }
        $tree = $this->sandboxTree();
        if (!$tree->isOk() || $tree->folder === null || $tree->folder->find($folderId) === null) {
            throw new \LogicException("Refusing to touch folder $folderId: not inside sandbox {$this->sandboxId}");
        }
    }

    /**
     * Response transform for root listings: keeps the sandbox, drops every
     * other (pre-existing, private) top-level FolderInfo and leaves a comment.
     */
    public function elideForeignFolders(): \Closure
    {
        $keep = $this->sandboxId;
        return static function (string $body) use ($keep): string {
            $doc = new \DOMDocument();
            if (!@$doc->loadXML($body)) {
                return $body;
            }
            $xp = new \DOMXPath($doc);
            $xp->registerNamespace('a', 'http://chomikuj.pl');
            $xp->registerNamespace('c', 'http://chomikuj.pl/');
            $list = $xp->query('//a:folder/c:folders')->item(0);
            if (!$list instanceof \DOMElement) {
                return $body;
            }
            $removed = 0;
            foreach (iterator_to_array($list->childNodes) as $child) {
                if ($child instanceof \DOMElement && $child->localName === 'FolderInfo') {
                    $id = (int) $xp->evaluate('string(c:id)', $child);
                    if ($id !== $keep) {
                        $list->removeChild($child);
                        $removed++;
                    }
                }
            }
            $list->insertBefore($doc->createComment(" $removed pre-existing FolderInfo elements elided by the recorder "), $list->firstChild);
            return $doc->saveXML($doc->documentElement);
        };
    }
}
