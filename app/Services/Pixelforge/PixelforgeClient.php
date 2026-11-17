<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Throwable;
use UnexpectedValueException;

final class PixelforgeClient
{
    private const MAX_RETRY_AFTER_SECONDS = 5;

    /** @return array<string, mixed> */
    public function pricing(): array
    {
        return $this->decode($this->request()->get('/v1/pricing'));
    }

    /**
     * The caller must reuse the same key and payload when resubmitting the
     * same logical generation, including after an uncertain outcome.
     *
     * @param  list<string>|null  $referenceImages
     * @param  array<string, string>|null  $metadata
     * @return array<string, mixed>
     */
    public function generate(
        string $idempotencyKey,
        string $prompt,
        string $size,
        string $quality,
        ?array $referenceImages = null,
        ?array $metadata = null,
    ): array {
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 255 || preg_match('/[\x00-\x1f\x7f]/', $idempotencyKey)) {
            throw new InvalidArgumentException('A valid Pixelforge idempotency key is required.');
        }

        $payload = array_filter([
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'metadata' => $metadata === null ? null : (object) $metadata,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->decode($this->authenticatedRequest()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post('/v1/generations', $payload));
    }

    /** @return array<string, mixed> */
    public function generation(string $id): array
    {
        return $this->decode($this->authenticatedRequest()->get('/v1/generations/'.rawurlencode($id)));
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    private function apiKey(): ?string
    {
        $key = config('services.pixelforge.key');

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    private function authenticatedRequest(): PendingRequest
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new LogicException('Pixelforge credentials are not configured.');
        }

        return $this->request()->withToken($apiKey);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config()->string('services.pixelforge.base_url'))
            ->acceptJson()
            ->withoutRedirecting()
            ->connectTimeout(3)
            ->timeout(10)
            ->retry(
                3,
                fn (int $attempt, Throwable $error): int => $error instanceof ConnectionException
                    ? 250 * $attempt
                    : max(250, ($this->retryDelay($error) ?? 0) * 1000) + 250 * ($attempt - 1),
                fn (?Throwable $error): bool => $error instanceof ConnectionException || $this->retryDelay($error) !== null,
            );
    }

    private function retryDelay(?Throwable $error): ?int
    {
        $seconds = $error instanceof RequestException ? $this->retryAfter($error) : null;

        // Longer waits are handled by the caller instead of blocking the request.
        return $seconds !== null && $seconds <= self::MAX_RETRY_AFTER_SECONDS ? $seconds : null;
    }

    public function retryAfter(RequestException $error): ?int
    {
        $response = $error->response;
        $code = $response->json('error.code');

        $retryable = match ($response->status()) {
            429 => in_array($code, ['rate_limit_exceeded', 'concurrency_limit_exceeded'], true),
            503 => $code === 'overloaded',
            default => false,
        };

        $seconds = trim($response->header('Retry-After'));

        if (! $retryable || ! ctype_digit($seconds)) {
            return null;
        }

        $value = filter_var(ltrim($seconds, '0') ?: '0', FILTER_VALIDATE_INT, [
            'options' => ['max_range' => 2_147_483_647],
        ]);

        return $value === false ? null : $value;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $response->throw(); // RequestException retains the original response and structured error.
        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || array_is_list($body)) {
            throw new UnexpectedValueException('Expected a successful Pixelforge JSON object response.');
        }

        return $body;
    }
}
