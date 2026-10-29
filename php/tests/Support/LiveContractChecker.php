<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Support;

use ChomikBox\ChomikBoxClient;
use ChomikBox\Http\CurlTransport;
use ChomikBox\Http\HttpRequest;
use ChomikBox\Http\HttpResponse;
use ChomikBox\Model\FolderInfo;

/**
 * Collects the live exchanges of observed clients and validates them against
 * the OpenAPI spec. Messages name operation, status and schema path only,
 * never payload values.
 */
final class LiveContractChecker
{
    /** @var list<array{0:HttpRequest, 1:HttpResponse, 2:bool}> */
    private array $pending = [];
    /** @var array<string, int> operationId => validated exchanges */
    public array $validated = [];
    private bool $invalidRequestExpected = false;

    public function observe(ChomikBoxClient $client): void
    {
        $http = $client->http();
        if ($http instanceof CurlTransport) {
            $http->addObserver(function (HttpRequest $req, HttpResponse $resp): void {
                $this->pending[] = [$req, $resp, $this->invalidRequestExpected];
            });
        }
    }

    /**
     * Runs $fn whose requests are deliberately malformed (e.g. a deserialization-fault
     * probe): their requests must NOT match the spec, their responses still must.
     */
    public function withDeliberatelyInvalidRequest(callable $fn): mixed
    {
        $this->invalidRequestExpected = true;
        try {
            return $fn();
        } finally {
            $this->invalidRequestExpected = false;
        }
    }

    /** @return list<string> problems found in the exchanges collected since the last call */
    public function drain(): array
    {
        $spec = SpecSupport::contract();
        $problems = [];
        foreach ($this->pending as [$req, $resp, $invalidExpected]) {
            $action = $req->header('SOAPAction');
            $path = (string) parse_url($req->url, PHP_URL_PATH);
            if ($action !== null) {
                $id = $spec->operationIdForSoapAction($action);
                if ($id === null) {
                    $problems[] = "unknown SOAPAction $action";
                    continue;
                }
                $r = $spec->validateRequestXml($id, $req->plain());
                if ($invalidExpected && $r->ok) {
                    $problems[] = "$id request: deliberately invalid request matched the spec";
                } elseif (!$invalidExpected && !$r->ok) {
                    $problems[] = "$id request: " . $r->summary(3);
                }
                $r = $spec->validateResponseXml($id, $resp->status, $resp->body);
                if (!$r->ok) {
                    $problems[] = "$id response {$resp->status}: " . $r->summary(3);
                }
            } elseif ($path === '/file/' && $req->method === 'POST') {
                $id = 'uploadFile';
                $r = $spec->validateMultipart($req->body, $spec->requestSchema($id, 'multipart/mixed'), $spec->multipartEncoding($id));
                if (!$r->ok) {
                    $problems[] = "$id request: " . $r->summary(3);
                }
                $f = $spec->validateChbFraming($req->body, (string) $req->header('Content-Type'), $spec->multipartEncoding($id));
                if (!$f->ok) {
                    $problems[] = "$id framing: " . $f->summary(3);
                }
                $r = $spec->validateResponseXml($id, $resp->status, $resp->body);
                if (!$r->ok) {
                    $problems[] = "$id response {$resp->status}: " . $r->summary(3);
                }
            } elseif ($path === '/resume/check/') {
                $id = 'resumeCheck';
                $r = $spec->validateResponseXml($id, $resp->status, $resp->body);
                if (!$r->ok) {
                    $problems[] = "$id response {$resp->status}: " . $r->summary(3);
                }
            } elseif ($path === '/File.aspx' && $req->method === 'GET') {
                $id = 'downloadFile';
                if (!array_key_exists((string) $resp->status, $spec->operation($id)['op']['responses'] ?? [])) {
                    $problems[] = "$id: status {$resp->status} not declared";
                }
            } else {
                $problems[] = "exchange not mapped to an operation: {$req->method} $path";
                continue;
            }
            $this->validated[$id] = ($this->validated[$id] ?? 0) + 1;
        }
        $this->pending = [];
        return $problems;
    }

    /**
     * sha256 over (id, name, parent) of every folder of the account tree; compared, never
     * printed with names. null when the tree could not be fetched (callers must fail then).
     */
    public static function treeFingerprint(ChomikBoxClient $client): ?string
    {
        $result = $client->folders(0, 0);
        $root = $result->folder;
        if (!$result->isOk() || $root === null || $root->id !== 0) {
            return null;
        }
        $nodes = [];
        $walk = static function (FolderInfo $f, int $parent) use (&$walk, &$nodes): void {
            $nodes[$f->id] = [$f->name, $parent];
            foreach ($f->folders ?? [] as $child) {
                $walk($child, $f->id);
            }
        };
        $walk($root, -1);
        ksort($nodes);
        return hash('sha256', serialize($nodes));
    }
}
