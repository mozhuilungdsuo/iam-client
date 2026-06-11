<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Nagaland\IamClient\Exceptions\IamConfigurationException;
use Nagaland\IamClient\Exceptions\IamTokenVerificationException;

final readonly class IdTokenVerifier
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private HttpFactory $http,
        private array $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(?string $idToken): array
    {
        if (! (bool) Arr::get($this->config, 'id_token.verify', true)) {
            return [];
        }

        if (! is_string($idToken) || $idToken === '') {
            throw new IamTokenVerificationException('IAM did not return an ID token.');
        }

        [$header, $payload, $signature, $signedPayload] = $this->decode($idToken);

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new IamTokenVerificationException('IAM ID token must use RS256.');
        }

        $key = $this->keyFor((string) ($header['kid'] ?? ''));

        if (openssl_verify($signedPayload, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new IamTokenVerificationException('IAM ID token signature is invalid.');
        }

        $this->verifyClaims($payload);

        return $payload;
    }

    public function assertSubject(?string $idToken, string $subject): void
    {
        if (! (bool) Arr::get($this->config, 'id_token.verify', true)) {
            return;
        }

        $claims = $this->verify($idToken);

        if (! hash_equals((string) ($claims['sub'] ?? ''), $subject)) {
            throw new IamTokenVerificationException('IAM ID token subject does not match the userinfo subject.');
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new IamTokenVerificationException('IAM ID token is malformed.');
        }

        $header = $this->json($this->base64UrlDecode($parts[0]));
        $payload = $this->json($this->base64UrlDecode($parts[1]));

        return [$header, $payload, $this->base64UrlDecode($parts[2]), $parts[0].'.'.$parts[1]];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $value): array
    {
        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            throw new IamTokenVerificationException('IAM ID token contains invalid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function verifyClaims(array $claims): void
    {
        $issuer = rtrim($this->baseUrl(), '/');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $leeway = (int) Arr::get($this->config, 'id_token.leeway', 60);
        $now = time();

        if (! hash_equals($issuer, (string) ($claims['iss'] ?? ''))) {
            throw new IamTokenVerificationException('IAM ID token issuer is invalid.');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? array_map('strval', $audience) : [(string) $audience];

        if (! in_array($clientId, $audiences, true)) {
            throw new IamTokenVerificationException('IAM ID token audience is invalid.');
        }

        if ((int) ($claims['exp'] ?? 0) < ($now - $leeway)) {
            throw new IamTokenVerificationException('IAM ID token has expired.');
        }

        if (isset($claims['nbf']) && (int) $claims['nbf'] > ($now + $leeway)) {
            throw new IamTokenVerificationException('IAM ID token is not valid yet.');
        }

        if (isset($claims['iat']) && (int) $claims['iat'] > ($now + $leeway)) {
            throw new IamTokenVerificationException('IAM ID token was issued in the future.');
        }
    }

    private function keyFor(string $keyId): string
    {
        if ($keyId === '') {
            throw new IamTokenVerificationException('IAM ID token is missing a key id.');
        }

        $jwks = $this->http
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->get($this->endpoint('jwks'))
            ->throw()
            ->json('keys');

        if (! is_array($jwks)) {
            throw new IamTokenVerificationException('IAM JWKS response is invalid.');
        }

        foreach ($jwks as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $keyId) {
                return $this->jwkToPem($key);
            }
        }

        throw new IamTokenVerificationException('IAM JWKS does not contain the ID token key.');
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    private function jwkToPem(array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            throw new IamTokenVerificationException('IAM JWKS key is not an RSA signing key.');
        }

        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);
        $rsaPublicKey = $this->sequence(
            $this->integer($modulus).
            $this->integer($exponent),
        );
        $publicKeyInfo = $this->sequence(
            $this->sequence($this->objectIdentifier('2a864886f70d010101').$this->null()).
            $this->bitString($rsaPublicKey),
        );

        return "-----BEGIN PUBLIC KEY-----\n".
            chunk_split(base64_encode($publicKeyInfo), 64, "\n").
            "-----END PUBLIC KEY-----\n";
    }

    private function endpoint(string $name): string
    {
        $endpoint = Arr::get($this->config, "endpoints.{$name}");

        if (! is_string($endpoint) || $endpoint === '') {
            throw new IamConfigurationException("Missing IAM endpoint [{$name}].");
        }

        return $endpoint;
    }

    private function baseUrl(): string
    {
        $url = $this->config['iam_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new IamConfigurationException('IAM_URL is not configured.');
        }

        return rtrim($url, '/');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value.str_repeat('=', (4 - strlen($value) % 4) % 4), '-_', '+/'), true);

        if (! is_string($decoded)) {
            throw new IamTokenVerificationException('IAM ID token has invalid base64url encoding.');
        }

        return $decoded;
    }

    private function sequence(string $value): string
    {
        return "\x30".$this->length($value).$value;
    }

    private function integer(string $value): string
    {
        $value = ltrim($value, "\x00");

        if ($value === '' || (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->length($value).$value;
    }

    private function objectIdentifier(string $hex): string
    {
        $value = hex2bin($hex);

        if (! is_string($value)) {
            throw new IamTokenVerificationException('Unable to encode IAM JWKS key.');
        }

        return "\x06".$this->length($value).$value;
    }

    private function null(): string
    {
        return "\x05\x00";
    }

    private function bitString(string $value): string
    {
        return "\x03".$this->length("\x00".$value)."\x00".$value;
    }

    private function length(string $value): string
    {
        $length = strlen($value);

        if ($length < 128) {
            return chr($length);
        }

        $encoded = '';

        while ($length > 0) {
            $encoded = chr($length & 0xFF).$encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)).$encoded;
    }
}
