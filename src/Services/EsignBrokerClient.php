<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Nagaland\IamClient\DTOs\EsignRequest;
use Nagaland\IamClient\Exceptions\IamConfigurationException;

final readonly class EsignBrokerClient
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private HttpFactory $http,
        private NagalandIamManager $iam,
        private array $config,
    ) {}

    public function create(
        UploadedFile $document,
        string $clientReference,
        ?string $state = null,
        string $mode = 'direct',
        ?string $signatureFieldName = null,
        ?string $signerName = null,
    ): EsignRequest {
        $path = $document->getRealPath();

        if ($path === false) {
            throw new \RuntimeException('The eSign document upload is not available.');
        }

        $response = $this->request()
            ->attach('document', fopen($path, 'r'), $document->getClientOriginalName())
            ->post($this->endpoint('create'), array_filter([
                'client_reference' => $clientReference,
                'completion_url' => $this->completionUrl(),
                'state' => $state,
                'mode' => $mode,
                'signature_field_name' => $signatureFieldName,
                'signer_name' => $signerName,
            ], static fn (mixed $value): bool => $value !== null));

        return EsignRequest::fromArray($response->throw()->json());
    }

    public function status(string $transactionId): EsignRequest
    {
        return EsignRequest::fromArray($this->request()->get(str_replace('{transaction}', $transactionId, $this->endpoint('show')))->throw()->json());
    }

    public function download(string $transactionId): string
    {
        return $this->request()->get(str_replace('{transaction}', $transactionId, $this->endpoint('download')))->throw()->body();
    }

    private function request(): PendingRequest
    {
        $tokens = $this->iam->tokens();

        if ($tokens === null) {
            throw new \RuntimeException('An authenticated IAM session is required to use eSign.');
        }

        return $this->http->baseUrl(rtrim($this->iamUrl(), '/'))->acceptJson()->withToken($tokens->accessToken);
    }

    private function completionUrl(): string
    {
        $url = $this->config['esign']['completion_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new IamConfigurationException('IAM_ESIGN_COMPLETION_URL is not configured.');
        }

        return $url;
    }

    private function endpoint(string $name): string
    {
        $endpoint = $this->config['esign']['endpoints'][$name] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            throw new IamConfigurationException("Missing IAM eSign endpoint [{$name}].");
        }

        return $endpoint;
    }

    private function iamUrl(): string
    {
        $url = $this->config['iam_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new IamConfigurationException('IAM_URL is not configured.');
        }

        return $url;
    }
}
