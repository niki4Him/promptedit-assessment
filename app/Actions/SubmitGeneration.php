<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgePricing;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;
use UnexpectedValueException;

final class SubmitGeneration
{
    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly PixelforgePricing $pricing,
        private readonly ApplyPixelforgeSnapshot $apply,
    ) {}

    /** @param list<string>|null $references */
    public function handle(User $user, string $prompt, string $size, string $quality, ?array $references): Generation
    {
        if (! $this->client->isConfigured()) {
            throw ValidationException::withMessages(['generation' => 'Image generation is temporarily unavailable.']);
        }

        $idempotencyKey = (string) Str::uuid();

        try {
            $estimate = $this->pricing->estimate($prompt, $size, $quality, count($references ?? []));
        } catch (ConnectionException|RequestException|InvalidArgumentException|OverflowException|UnexpectedValueException) {
            throw ValidationException::withMessages(['generation' => 'Image pricing is temporarily unavailable. Please try again later.']);
        }

        $generation = $user->generations()->create([
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $references,
            'idempotency_key' => $idempotencyKey,
            'estimated_provider_cost_nanodollars' => $estimate['provider_cost_nanodollars'],
            'estimated_user_charge_nanodollars' => $estimate['user_charge_nanodollars'],
        ]);

        try {
            $this->apply->handle($generation->id, $this->send($generation));
        } catch (RequestException $error) {
            $this->handleRejection($generation, $error);
        } catch (ConnectionException|InvalidArgumentException|OverflowException|UniqueConstraintViolationException|UnexpectedValueException|ValidationException) {
            throw $this->pendingSubmission();
        }

        return $generation;
    }

    /** @return array<string, mixed> */
    public function send(Generation $generation): array
    {
        // Submission and recovery always use this exact payload from the saved request.
        return $this->client->generate(
            $generation->idempotency_key,
            $generation->prompt,
            $generation->size,
            $generation->quality,
            $generation->reference_images,
            ['generation_id' => (string) $generation->id],
        );
    }

    private function handleRejection(Generation $generation, RequestException $error): never
    {
        $response = $error->response;
        $code = $response->json('error.code');
        $message = $response->json('error.message');

        // These errors reject the unchanged payload. Transient errors can follow an
        // earlier attempt that reached Pixelforge, so keep those submissions pending.
        if (($response->status() === 400 && $code === 'content_policy_violation')
            || ($response->status() === 422 && $code === 'validation_failed')) {
            $field = $response->json('error.param');
            $field = is_string($field) && preg_match('/\A(?:prompt|size|quality|reference_images(?:\.[0-3])?)\z/', $field)
                ? $field
                : ($code === 'content_policy_violation' ? 'prompt' : 'generation');
            $message = is_string($message) && $message !== '' ? $message : 'Pixelforge rejected the generation request.';

            Generation::query()->whereKey($generation->id)->unsettled()->whereNull('provider_generation_id')->update([
                'status' => GenerationStatus::Failed->value,
                'actual_provider_cost_nanodollars' => 0,
                'actual_user_charge_nanodollars' => 0,
                'provider_error_code' => $code,
                'provider_error_message' => $message,
                'completed_at' => now(),
            ]);

            throw ValidationException::withMessages([$field => $message]);
        }

        $reason = match ($code) {
            'rate_limit_exceeded' => 'Pixelforge is receiving too many requests.',
            'concurrency_limit_exceeded' => 'Pixelforge has too many generations in progress.',
            'daily_limit_exceeded' => 'Pixelforge has reached its daily generation limit.',
            'overloaded' => 'Pixelforge is temporarily overloaded.',
            default => 'Pixelforge could not confirm the submission.',
        };

        $retryAfter = $this->client->retryAfter($error);

        if ($retryAfter !== null) {
            $generation->deferRecoveryUntil(now()->addSeconds($retryAfter));
            $reason .= " Pixelforge requested a wait of {$retryAfter} seconds before retrying.";
        }

        throw $this->pendingSubmission($reason);
    }

    private function pendingSubmission(string $reason = 'Pixelforge could not confirm the submission.'): ValidationException
    {
        return ValidationException::withMessages([
            'generation' => $reason.' The submission was saved for recovery. Submitting again would start a new request.',
        ]);
    }
}
