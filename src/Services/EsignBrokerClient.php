<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Nagaland\IamClient\DTOs\EsignRequest;
use Nagaland\IamClient\Exceptions\IamConfigurationException;

/**
 * Authenticated client for the IAM-hosted eSign broker.
 *
 * The broker prepares and submits the C-DAC eSign request for an IAM OAuth
 * client. Applications use this client to create a transaction,
 * redirect the browser to its one-time handoff URL, and later retrieve the
 * authoritative transaction status or completed document.
 *
 * Every operation uses the OAuth client's static eSign API key. Generate or
 * rotate this key in IAM and configure it as `IAM_ESIGN_API_KEY`; IAM checks
 * that the client allows `esign.request` or `esign.read`, as appropriate.
 *
 * @see EsignRequest The normalized broker response returned by create and status.
 */
final readonly class EsignBrokerClient
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private HttpFactory $http,
        private array $config,
    ) {}

    /**
     * Creates, or returns the existing, eSign transaction for a client reference.
     *
     * The document is uploaded as multipart form data under the `document` field.
     * IAM uses the configured completion URL and returns a handoff URL for the
     * browser to visit. Store the supplied state in the user's session before
     * redirecting to that URL, then verify it when the completion URL is called.
     *
     * Reusing a client reference for the same OAuth client is idempotent: IAM
     * returns the original transaction rather than creating another one.
     *
     * @param UploadedFile $document PDF, JPG, JPEG, or PNG accepted by IAM (up to its configured limit).
     * @param string $clientReference Application-owned, ASCII alpha-dash identifier (maximum 160 characters).
     * @param string|null $state Opaque value returned to the configured completion URL after signing.
     * @param 'direct'|'visible' $mode Signature-placement mode; defaults to direct.
     * @param string|null $signatureFieldName Optional signature-field name, used by direct mode.
     * @param string|null $signerName Optional signer name, used by visible mode when supplied.
     *
     * @throws \RuntimeException When the uploaded file is unavailable.
     * @throws IamConfigurationException When IAM_URL, the eSign API key, completion URL, or create endpoint is missing.
     * @throws \Illuminate\Http\Client\RequestException When IAM rejects the request or is unreachable.
     */
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

    /**
     * Retrieves the current, authoritative state of an eSign transaction.
     *
     * Only the IAM user and OAuth client that created the transaction can read it.
     * Use this method after validating the `esign_transaction_id` and `state`
     * returned to the completion URL; do not infer completion from query-string
     * parameters.
     *
     * @param string $transactionId IAM's public transaction identifier.
     *
     * @throws IamConfigurationException When IAM_URL, the eSign API key, or the show endpoint is missing.
     * @throws \Illuminate\Http\Client\RequestException When IAM rejects the request or is unreachable.
     */
    public function status(string $transactionId): EsignRequest
    {
        return EsignRequest::fromArray($this->request()->get(str_replace('{transaction}', $transactionId, $this->endpoint('show')))->throw()->json());
    }

    /**
     * Downloads the completed signed document as binary data.
     *
     * Call {@see status()} first and download only when {@see EsignRequest::completed()}
     * returns true. IAM returns a conflict response while the transaction is not
     * complete, and restricts access to its creator.
     *
     * @param string $transactionId IAM's public transaction identifier.
     * @return string Raw bytes of the signed document.
     *
     * @throws IamConfigurationException When IAM_URL, the eSign API key, or the download endpoint is missing.
     * @throws \Illuminate\Http\Client\RequestException When IAM rejects the request or is unreachable.
     */
    public function download(string $transactionId): string
    {
        return $this->request()->get(str_replace('{transaction}', $transactionId, $this->endpoint('download')))->throw()->body();
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->iamUrl(), '/'))
            ->acceptJson()
            ->withHeader('X-IAM-eSign-Key', $this->apiKey());
    }

    private function apiKey(): string
    {
        $key = $this->config['esign']['api_key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new IamConfigurationException('IAM_ESIGN_API_KEY is not configured.');
        }

        return $key;
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
