<?php

declare(strict_types=1);

namespace App\Domain\Content\Services\Tts;

use App\Domain\Content\Exceptions\SpeechSynthesisException;
use Firebase\JWT\JWT;
use JsonException;
use LogicException;
use OpenSSLAsymmetricKey;
use Throwable;

/**
 * The service account of the `google` driver (docs/26 §9.1): reads and checks the key file of `twa.tts.google.credentials`
 * (`GOOGLE_TTS_CREDENTIALS` - a PATH, never the JSON itself) and signs the self-signed JWT that goes straight into
 * `Authorization: Bearer` (no exchange at oauth2.googleapis.com, nothing in Redis or the database).
 *
 * A singleton: the parsed key and the last token live in this object only. It cannot be serialized (a job property, a cache
 * value) and dumps only the client email. Every failure is a bare code: no `previous`, no text from the file.
 */
final class GoogleServiceAccount
{
    /** The audience of a self-signed JWT for the Text-to-Speech API (with the trailing slash). */
    public const AUDIENCE = 'https://texttospeech.googleapis.com/';

    /** Google accepts at most an hour. */
    private const LIFETIME = 3600;

    /** Clock skew allowance: the token is dated this much in the past. */
    private const SKEW = 30;

    /** A token with less than this left is signed again. */
    private const RENEW_BEFORE = 300;

    /** A service-account key file is ~2.3 KB; anything much larger is not one. */
    private const MAX_FILE_BYTES = 16_384;

    /** The path the key below was read from: a changed config (tests, a reload) reads the file again. */
    private ?string $path = null;

    private ?string $clientEmail = null;

    private ?string $keyId = null;

    private ?OpenSSLAsymmetricKey $privateKey = null;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    /**
     * The JWT for `Authorization: Bearer`, signed again when less than 5 minutes are left or when `$fresh` (after a 401).
     *
     * @throws SpeechSynthesisException tts_service_not_configured (no path) | tts_credentials_invalid
     */
    public function bearerToken(bool $fresh = false): string
    {
        $this->load();

        if ($fresh || $this->token === null || $this->tokenExpiresAt - now()->getTimestamp() < self::RENEW_BEFORE) {
            $this->sign();
        }

        return (string) $this->token;
    }

    /**
     * Reads and checks the key file without signing or calling anyone (the reconciliation asks it before queueing a night of
     * jobs that would all fail). Throws the same bare codes as {@see bearerToken()}.
     *
     * @throws SpeechSynthesisException tts_service_not_configured (no path) | tts_credentials_invalid
     */
    public function check(): void
    {
        $this->load();
    }

    /** @return array{client_email: string|null} */
    public function __debugInfo(): array
    {
        return ['client_email' => $this->clientEmail];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('The Google service account holds a private key and cannot be serialized.');
    }

    /** @param  array<string, mixed>  $data */
    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        throw new LogicException('The Google service account cannot be unserialized.');
    }

    private function load(): void
    {
        $path = (string) config('twa.tts.google.credentials', '');
        if ($path === '') {
            $this->forget();

            throw SpeechSynthesisException::notConfigured();
        }

        if ($this->privateKey !== null && $this->path === $path) {
            return;
        }

        $this->forget();
        $this->parse($this->readFile($path));
        $this->path = $path;
    }

    private function readFile(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw SpeechSynthesisException::credentialsInvalid();
        }

        $size = filesize($path);
        if ($size === false || $size === 0 || $size > self::MAX_FILE_BYTES) {
            throw SpeechSynthesisException::credentialsInvalid();
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            throw SpeechSynthesisException::credentialsInvalid();
        }

        return $json;
    }

    private function parse(#[\SensitiveParameter] string $json): void
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SpeechSynthesisException::credentialsInvalid();
        }

        if (! is_array($data) || ($data['type'] ?? null) !== 'service_account') {
            throw SpeechSynthesisException::credentialsInvalid();
        }

        $email = $data['client_email'] ?? null;
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw SpeechSynthesisException::credentialsInvalid();
        }

        $keyId = $data['private_key_id'] ?? null;
        if (! is_string($keyId) || preg_match('/\A[A-Za-z0-9]+\z/', $keyId) !== 1) {
            throw SpeechSynthesisException::credentialsInvalid();
        }

        $pem = $data['private_key'] ?? null;
        $key = is_string($pem) ? openssl_pkey_get_private($pem) : false;
        $details = $key !== false ? openssl_pkey_get_details($key) : false;
        $this->clearOpenSslErrors();
        if ($key === false || $details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($details['bits'] ?? 0) < 2048) {
            throw SpeechSynthesisException::credentialsInvalid();
        }

        $this->clientEmail = $email;
        $this->keyId = $keyId;
        $this->privateKey = $key;
    }

    private function sign(): void
    {
        if ($this->privateKey === null || $this->clientEmail === null || $this->keyId === null) {
            throw SpeechSynthesisException::credentialsInvalid();
        }

        $issuedAt = now()->getTimestamp() - self::SKEW;
        $claims = [
            'iss' => $this->clientEmail,
            'sub' => $this->clientEmail,
            'aud' => self::AUDIENCE,
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::LIFETIME,
        ];

        try {
            $this->token = JWT::encode($claims, $this->privateKey, 'RS256', $this->keyId);
        } catch (Throwable) {
            $this->token = null;
            $this->clearOpenSslErrors();

            throw SpeechSynthesisException::credentialsInvalid();
        }

        $this->tokenExpiresAt = $issuedAt + self::LIFETIME;
    }

    private function forget(): void
    {
        $this->path = null;
        $this->clientEmail = null;
        $this->keyId = null;
        $this->privateKey = null;
        $this->token = null;
        $this->tokenExpiresAt = 0;
    }

    /** OpenSSL keeps its errors in a per-process queue; a failed parse must not leave them for the next caller. */
    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // drain
        }
    }
}
