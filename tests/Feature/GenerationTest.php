<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->submission = [
        'idempotency_key' => (string) Str::uuid(),
        'prompt' => 'Draw a lighthouse',
        'size' => '1024x1024',
        'quality' => 'low',
        'estimated_provider_cost_nanodollars' => 14_335_000,
        'estimated_user_charge_nanodollars' => 17_918_750,
    ];
});

test('a generation belongs to its user and appears in only that user’s history', function () {
    $generation = $this->user->generations()->create($this->submission);

    expect($generation->user->is($this->user))->toBeTrue()
        ->and($this->user->generations->sole()->is($generation))->toBeTrue()
        ->and(User::factory()->create()->generations)->toBeEmpty();
});

test('generations can be recorded locally before Pixelforge assigns a provider ID', function () {
    $first = $this->user->generations()->create($this->submission)->refresh();
    $second = $this->user->generations()->create(array_replace($this->submission, [
        'idempotency_key' => (string) Str::uuid(),
    ]))->refresh();

    expect($first->id)->not->toBe($second->id)
        ->and($first->provider_generation_id)->toBeNull()
        ->and($second->provider_generation_id)->toBeNull()
        ->and($first->status)->toBe(GenerationStatus::Queued)
        ->and($first->reference_images)->toBeNull()
        ->and($first->rates)->toBeNull()
        ->and($first->usage)->toBeNull()
        ->and($first->actual_provider_cost_nanodollars)->toBeNull()
        ->and($first->actual_user_charge_nanodollars)->toBeNull()
        ->and($first->completed_at)->toBeNull();
});

test('idempotency keys are unique across users', function () {
    $this->user->generations()->create($this->submission);

    expect(fn () => User::factory()->create()->generations()->create($this->submission))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a provider generation ID can be assigned to only one local generation', function () {
    $first = $this->user->generations()->create($this->submission);
    $second = $this->user->generations()->create(array_replace($this->submission, [
        'idempotency_key' => (string) Str::uuid(),
    ]));
    $first->provider_generation_id = 'gen_example';
    $first->saveOrFail();

    $second->provider_generation_id = 'gen_example';

    expect(fn () => $second->saveOrFail())->toThrow(UniqueConstraintViolationException::class);
});

test('a generation must reference an existing user', function () {
    $generation = new Generation($this->submission);
    $generation->user_id = $this->user->id + 1;

    expect(fn () => $generation->saveOrFail())->toThrow(QueryException::class);
});

test('casts stored provider data and preserves exact nanodollar amounts', function () {
    // Odd integers above 2^53 expose accidental conversion through a double.
    $providerCost = 9_007_199_254_740_993;
    $userCharge = 11_258_999_068_426_241;
    $references = ['https://example.com/style.png'];
    $generation = $this->user->generations()->create(array_replace($this->submission, [
        'reference_images' => $references,
        'estimated_provider_cost_nanodollars' => $providerCost,
        'estimated_user_charge_nanodollars' => $userCharge,
    ]));
    $rates = ['input_text_tokens' => '5.00', 'reasoning_tokens' => '12.50'];
    $usage = ['input_text_tokens' => 5, 'reasoning_tokens' => 100];
    $completedAt = CarbonImmutable::parse('2026-11-17T10:00:30Z');

    $generation->forceFill([
        'status' => GenerationStatus::Succeeded,
        'rates' => $rates,
        'usage' => $usage,
        'provider_created_at' => $completedAt->subSeconds(30),
        'started_at' => $completedAt->subSeconds(15),
        'completed_at' => $completedAt,
        'image_url' => 'https://example.com/image.png?signature=abc',
        'image_url_expires_at' => $completedAt->addDay(),
        'actual_provider_cost_nanodollars' => $providerCost,
        'actual_user_charge_nanodollars' => $userCharge,
    ])->saveOrFail();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->reference_images)->toBe($references)
        ->and($generation->rates)->toBe($rates)
        ->and($generation->usage)->toBe($usage)
        ->and($generation->estimated_provider_cost_nanodollars)->toBe($providerCost)
        ->and($generation->estimated_user_charge_nanodollars)->toBe($userCharge)
        ->and($generation->actual_provider_cost_nanodollars)->toBe($providerCost)
        ->and($generation->actual_user_charge_nanodollars)->toBe($userCharge)
        ->and($generation->image_url)->toBe('https://example.com/image.png?signature=abc')
        ->and($generation->provider_created_at)->toBeInstanceOf(CarbonImmutable::class)->toEqual($completedAt->subSeconds(30))
        ->and($generation->started_at)->toBeInstanceOf(CarbonImmutable::class)->toEqual($completedAt->subSeconds(15))
        ->and($generation->completed_at)->toBeInstanceOf(CarbonImmutable::class)->toEqual($completedAt)
        ->and($generation->image_url_expires_at)->toBeInstanceOf(CarbonImmutable::class)->toEqual($completedAt->addDay());
});

test('a failed generation can store a finalized zero charge and the provider error', function () {
    $generation = $this->user->generations()->create($this->submission);
    $generation->forceFill([
        'status' => GenerationStatus::Failed,
        'actual_provider_cost_nanodollars' => 0,
        'actual_user_charge_nanodollars' => 0,
        'provider_error_code' => 'generation_failed',
        'provider_error_message' => 'The model could not produce an image.',
        'completed_at' => now(),
    ])->saveOrFail();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->actual_provider_cost_nanodollars)->toBe(0)
        ->and($generation->actual_user_charge_nanodollars)->toBe(0)
        ->and($generation->provider_error_code)->toBe('generation_failed')
        ->and($generation->provider_error_message)->toBe('The model could not produce an image.');
});
