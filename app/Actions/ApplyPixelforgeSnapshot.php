<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgePricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ApplyPixelforgeSnapshot
{
    private const UTC_TIMESTAMP = [
        'date_format:Y-m-d\TH:i:s\Z,Y-m-d\TH:i:s.u\Z,Y-m-d\TH:i:s.v\Z,Y-m-d\TH:i:sP,Y-m-d\TH:i:s.uP,Y-m-d\TH:i:s.vP',
        'regex:/\A.+(?:Z|\+00:00)\z/',
    ];

    public function __construct(private readonly PixelforgePricing $pricing) {}

    /** @param array<string, mixed> $snapshot */
    public function handle(
        int $localId,
        array $snapshot,
        ?GenerationStatus $expectedStatus = null,
        ?string $expectedProviderId = null,
    ): void {
        $identity = Validator::make($snapshot, [
            'id' => ['required', 'string', 'max:255', 'regex:/\Agen_[a-zA-Z0-9_-]+\z/'],
            'object' => ['sometimes', 'required', 'in:generation'],
            'status' => ['required', Rule::enum(GenerationStatus::class)],
        ])->validate();

        $status = GenerationStatus::from($identity['status']);
        $providerId = $identity['id'];
        $metadataId = data_get($snapshot, 'metadata.generation_id');

        if (($metadataId !== null && $metadataId !== (string) $localId)
            || ($expectedStatus !== null && $expectedStatus !== $status)
            || ($expectedProviderId !== null && $expectedProviderId !== $providerId)) {
            throw ValidationException::withMessages(['generation' => 'The Pixelforge snapshot does not match the generation.']);
        }

        DB::transaction(function () use ($localId, $snapshot, $status, $providerId): void {
            $generation = Generation::query()->lockForUpdate()->findOrFail($localId);

            if ($generation->provider_generation_id !== null && $generation->provider_generation_id !== $providerId) {
                throw ValidationException::withMessages(['generation' => 'The Pixelforge generation ID does not match.']);
            }

            $generation->provider_generation_id = $providerId;

            if ($generation->isUnsettled()
                && ! ($status === GenerationStatus::Queued && $generation->status === GenerationStatus::Processing)) {
                $generation->forceFill($this->attributes($snapshot, $status));
            }

            if ($generation->isDirty() && ! $generation->save()) {
                throw new RuntimeException('Could not persist the Pixelforge generation update.');
            }
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function attributes(array $snapshot, GenerationStatus $status): array
    {
        $rules = [
            'rates' => ['nullable', 'array'],
            'created_at' => ['nullable', ...self::UTC_TIMESTAMP],
            'started_at' => ['nullable', ...self::UTC_TIMESTAMP],
        ];

        if ($status === GenerationStatus::Succeeded) {
            $rules['rates'] = ['required', 'array'];
            $rules += [
                'usage' => ['required', 'array'],
                'image_url' => ['required', 'string', 'url:http,https'],
                'image_url_expires_at' => ['required', ...self::UTC_TIMESTAMP],
                'completed_at' => ['required', ...self::UTC_TIMESTAMP],
            ];
        }

        if ($status === GenerationStatus::Failed) {
            $rules += [
                'error.code' => ['required', 'string', 'max:255'],
                'error.message' => ['required', 'string'],
                'completed_at' => ['required', ...self::UTC_TIMESTAMP],
            ];
        }

        $data = Validator::make($snapshot, $rules)->validate();
        $attributes = [
            'status' => $status,
            'rates' => $data['rates'] ?? null,
            'provider_created_at' => $data['created_at'] ?? null,
            'started_at' => $data['started_at'] ?? null,
        ];

        if (in_array($status, [GenerationStatus::Succeeded, GenerationStatus::Failed], true)) {
            $attributes += $this->finalAmounts($data + ['status' => $status->value], $status);
        }

        return array_filter($attributes, fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function finalAmounts(array $snapshot, GenerationStatus $status): array
    {
        $amounts = $this->pricing->actual($snapshot);

        return [
            'actual_provider_cost_nanodollars' => $amounts['provider_cost_nanodollars'],
            'actual_user_charge_nanodollars' => $amounts['user_charge_nanodollars'],
            'completed_at' => $snapshot['completed_at'],
        ] + match ($status) {
            GenerationStatus::Succeeded => [
                'usage' => $snapshot['usage'],
                'image_url' => $snapshot['image_url'],
                'image_url_expires_at' => $snapshot['image_url_expires_at'],
            ],
            GenerationStatus::Failed => [
                'provider_error_code' => $snapshot['error']['code'],
                'provider_error_message' => $snapshot['error']['message'],
            ],
            default => [],
        };
    }
}
