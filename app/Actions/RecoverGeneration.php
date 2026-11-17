<?php

namespace App\Actions;

use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use LogicException;
use OverflowException;
use UnexpectedValueException;

final class RecoverGeneration
{
    // The HTTP client allows three 10-second attempts plus bounded backoff.
    private const COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly SubmitGeneration $submit,
        private readonly ApplyPixelforgeSnapshot $apply,
    ) {}

    public function handle(Generation $generation): void
    {
        if (! $generation->isUnsettled()) {
            return;
        }

        if (! $this->client->isConfigured()) {
            throw ValidationException::withMessages(['recovery' => 'Image generation is temporarily unavailable.']);
        }

        $now = now();
        $cutoff = $now->copy()->subSeconds(self::COOLDOWN_SECONDS);
        $claimed = Generation::query()->whereKey($generation->id)->unsettled()
            // Give the original submission time to finish before recovery can resend it.
            ->where('created_at', '<=', $cutoff)
            ->where(fn ($query) => $query->whereNull('last_recovery_attempt_at')
                ->orWhere('last_recovery_attempt_at', '<=', $cutoff))
            ->where(fn ($query) => $query->whereNull('provider_retry_at')->orWhere('provider_retry_at', '<=', $now))
            ->update(['last_recovery_attempt_at' => $now]);

        $generation->refresh();

        if (! $generation->isUnsettled()) {
            return;
        }

        if ($claimed === 0) {
            throw ValidationException::withMessages(['recovery' => 'Please wait before checking this generation again.']);
        }

        $providerId = $generation->provider_generation_id;

        try {
            $snapshot = $providerId === null
                ? $this->submit->send($generation)
                : $this->client->generation($providerId);

            $this->apply->handle($generation->id, $snapshot, expectedProviderId: $providerId);
        } catch (RequestException $error) {
            $retryAfter = $this->client->retryAfter($error);

            if ($retryAfter !== null) {
                $generation->deferRecoveryUntil(now()->addSeconds($retryAfter));
                throw ValidationException::withMessages(['recovery' => "Pixelforge asked to wait {$retryAfter} seconds before trying again."]);
            }

            $message = $error->response->status() === 404 && $providerId !== null
                ? 'Pixelforge could not find this generation. Its local record is still pending.'
                : 'Pixelforge could not confirm the status. The generation is still available in history.';

            throw ValidationException::withMessages(['recovery' => $message]);
        } catch (ConnectionException|LogicException|OverflowException|UniqueConstraintViolationException|UnexpectedValueException|ValidationException) {
            throw ValidationException::withMessages(['recovery' => 'Pixelforge could not confirm the status. The generation is still available in history.']);
        }
    }
}
