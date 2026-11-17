<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<string>|null $reference_images
 * @property GenerationStatus $status
 * @property CarbonImmutable|null $image_url_expires_at
 */
#[Fillable([
    'idempotency_key',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'estimated_provider_cost_nanodollars',
    'estimated_user_charge_nanodollars',
])]
class Generation extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => GenerationStatus::Queued->value,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUnsettled(): bool
    {
        return in_array($this->status, [GenerationStatus::Queued, GenerationStatus::Processing], true)
            && $this->actual_provider_cost_nanodollars === null
            && $this->actual_user_charge_nanodollars === null;
    }

    public function deferRecoveryUntil(DateTimeInterface $deadline): void
    {
        self::query()->whereKey($this->getKey())->unsettled()
            ->where(fn (Builder $query) => $query->whereNull('provider_retry_at')->orWhere('provider_retry_at', '<', $deadline))
            ->update(['provider_retry_at' => $deadline]);
    }

    /** @param Builder<Generation> $query */
    #[Scope]
    protected function unsettled(Builder $query): void
    {
        $query->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->whereNull('actual_provider_cost_nanodollars')
            ->whereNull('actual_user_charge_nanodollars');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'estimated_provider_cost_nanodollars' => 'integer',
            'estimated_user_charge_nanodollars' => 'integer',
            'actual_provider_cost_nanodollars' => 'integer',
            'actual_user_charge_nanodollars' => 'integer',
            'rates' => 'array',
            'usage' => 'array',
            'image_url_expires_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
