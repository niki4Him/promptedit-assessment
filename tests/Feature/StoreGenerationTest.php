<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test',
        'services.pixelforge.key' => 'test-key',
    ]);
    Http::preventStrayRequests();
    Sleep::fake();

    $this->rates = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];
    $this->pricing = [
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => $this->rates,
        'tokens' => [
            'characters_per_text_token' => 4,
            'per_reference_image' => 765,
            'output' => [
                'medium' => ['1024x1024' => 1100],
                'high' => ['1536x1024' => 6450],
            ],
            'reasoning' => [
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
    $this->queued = [
        'id' => 'gen_example',
        'status' => 'queued',
        'rates' => $this->rates,
        'created_at' => '2026-11-17T09:00:00Z',
    ];
});

test('guests cannot submit a generation', function () {
    Http::fake();

    $this->post(route('generations.store'), ['prompt' => 'Draw a fox'])
        ->assertRedirect(route('login'));

    $this->assertDatabaseCount('generations', 0);
    Http::assertNothingSent();
});

test('invalid forms are rejected before calling Pixelforge', function (array $fields, string $errorKey) {
    Http::fake();

    $this->actingAs(User::factory()->create())->from(route('dashboard'))
        ->post(route('generations.store'), $fields)
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors($errorKey);

    $this->assertDatabaseCount('generations', 0);
    Http::assertNothingSent();
})->with([
    'required prompt' => [[], 'prompt'],
    'string prompt' => [['prompt' => ['draw']], 'prompt'],
    'prompt over 4000 Unicode characters' => [['prompt' => str_repeat('猫', 4001)], 'prompt'],
    'unsupported size' => [['prompt' => 'Fox', 'size' => '512x512'], 'size'],
    'unsupported quality' => [['prompt' => 'Fox', 'quality' => 'best'], 'quality'],
    'references must be an array' => [['prompt' => 'Fox', 'reference_images' => 'https://example.com/image'], 'reference_images'],
    'references must be a list' => [['prompt' => 'Fox', 'reference_images' => ['style' => 'https://example.com/image']], 'reference_images'],
    'too many references' => [['prompt' => 'Fox', 'reference_images' => array_fill(0, 5, 'https://example.com/image')], 'reference_images'],
    'references must use HTTP or HTTPS' => [['prompt' => 'Fox', 'reference_images' => ['ftp://example.com/image']], 'reference_images.0'],
    'references must be valid URLs' => [['prompt' => 'Fox', 'reference_images' => ['not-a-url']], 'reference_images.0'],
]);

test('stores the estimate and idempotency key before submission and correlates via metadata', function () {
    $user = User::factory()->create();
    $references = ['https://example.com/style.png', 'http://example.com/pose.png'];
    $payload = ['prompt' => 'Draw a fox', 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => $references];
    Http::fake([
        'https://pixelforge.test/v1/pricing' => function () {
            expect(Generation::query()->exists())->toBeFalse();

            return Http::response($this->pricing);
        },
        'https://pixelforge.test/v1/generations' => function (Request $request) use ($user, $payload) {
            $generation = Generation::query()->sole();

            expect($generation->user->is($user))->toBeTrue()
                ->and($generation->provider_generation_id)->toBeNull()
                ->and($generation->status)->toBe(GenerationStatus::Queued)
                ->and($generation->estimated_provider_cost_nanodollars)->toBe(329_565_000)
                ->and($generation->estimated_user_charge_nanodollars)->toBe(411_956_250)
                ->and(Str::isUuid($generation->idempotency_key))->toBeTrue()
                ->and($request->header('Idempotency-Key'))->toBe([$generation->idempotency_key])
                ->and(json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR))->toBe([
                    ...$payload,
                    'metadata' => ['generation_id' => (string) $generation->getKey()],
                ]);

            return Http::response($this->queued, 202);
        },
    ]);

    $this->actingAs($user)->from(route('dashboard'))
        ->post(route('generations.store'), $payload)
        ->assertStatus(303)->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

    $generation = Generation::query()->sole();
    expect($generation->provider_generation_id)->toBe('gen_example')
        ->and($generation->reference_images)->toBe($references)
        ->and($generation->rates)->toBe($this->rates)
        ->and($generation->provider_created_at->toIso8601String())->toBe('2026-11-17T09:00:00+00:00')
        ->and($generation->actual_user_charge_nanodollars)->toBeNull();
    Http::assertSentCount(2);
});

test('applies provider defaults and accepts a prompt of exactly 4000 Unicode characters', function () {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response($this->pricing),
        'https://pixelforge.test/v1/generations' => Http::response($this->queued, 202),
    ]);

    $this->actingAs(User::factory()->create())->from(route('dashboard'))
        ->post(route('generations.store'), ['prompt' => str_repeat('猫', 4000)])
        ->assertStatus(303)->assertSessionHasNoErrors();

    $generation = Generation::query()->sole();
    expect($generation->size)->toBe('1024x1024')->and($generation->quality)->toBe('medium');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['size'] === '1024x1024' && $request['quality'] === 'medium' && ! isset($request['reference_images']));
});

test('unavailable or malformed pricing does not create a submission', function (int $status, array $pricingOverrides) {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(array_replace($this->pricing, $pricingOverrides), $status),
    ]);

    $this->actingAs(User::factory()->create())->from(route('dashboard'))
        ->post(route('generations.store'), ['prompt' => 'Fox'])
        ->assertSessionHasErrors(['generation' => 'Image pricing is temporarily unavailable. Please try again later.']);

    $this->assertDatabaseCount('generations', 0);
    Http::assertSentCount(1);
})->with([
    'provider outage' => [500, []],
    'malformed rates' => [200, ['rates' => 'not a rates object']],
]);

test('uses safe validation messages and records confirmed rejections at zero cost', function (
    int $status, string $code, ?string $param, string $errorKey, string $message,
) {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response($this->pricing),
        'https://pixelforge.test/v1/generations' => Http::response([
            'error' => ['code' => $code, 'message' => 'Sensitive provider response details.', 'param' => $param],
        ], $status),
    ]);

    $this->actingAs(User::factory()->create())->from(route('dashboard'))
        ->post(route('generations.store'), ['prompt' => 'Fox'])
        ->assertRedirect(route('dashboard'))->assertSessionHasErrors([$errorKey => $message]);

    $generation = Generation::query()->sole();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->provider_generation_id)->toBeNull()
        ->and($generation->provider_error_code)->toBe($code)
        ->and($generation->provider_error_message)->toBe($message)
        ->and($generation->completed_at)->not->toBeNull()
        ->and($generation->actual_provider_cost_nanodollars)->toBe(0)
        ->and($generation->actual_user_charge_nanodollars)->toBe(0);
    Http::assertSentCount(2);
})->with([
    'content policy' => [400, 'content_policy_violation', null, 'prompt', 'The prompt was rejected by Pixelforge.'],
    'validation' => [422, 'validation_failed', 'size', 'size', 'Pixelforge rejected the generation request. Check your inputs and try again.'],
]);

test('leaves throttled or overloaded submissions pending with a bounded retry policy', function (
    int $status, string $code, string $wait, int $attempts,
) {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response($this->pricing),
        'https://pixelforge.test/v1/generations' => Http::response(['error' => ['code' => $code]], $status, ['Retry-After' => $wait]),
    ]);

    $this->actingAs(User::factory()->create())->from(route('dashboard'))
        ->post(route('generations.store'), ['prompt' => 'Fox'])
        ->assertSessionHasErrors('generation');

    expect(session('errors')->first('generation'))->toContain("wait of {$wait} seconds");
    $generation = Generation::query()->sole();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->actual_user_charge_nanodollars)->toBeNull();
    Http::assertSentCount(1 + $attempts);
    Sleep::assertSleptTimes($attempts - 1);
})->with([
    'long rate limit' => [429, 'rate_limit_exceeded', '30', 1],
    'concurrency limit' => [429, 'concurrency_limit_exceeded', '5', 3],
    'overloaded' => [503, 'overloaded', '2', 3],
]);

test('retains pending data after uncertain network failures and reuses the saved key on every attempt', function () {
    $requests = [];
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response($this->pricing),
        'https://pixelforge.test/v1/generations' => function (Request $request) use (&$requests) {
            $requests[] = [$request->header('Idempotency-Key'), $request->body()];

            return Http::failedConnection();
        },
    ]);

    $this->actingAs(User::factory()->create())->from(route('dashboard'))
        ->post(route('generations.store'), ['prompt' => 'Fox'])
        ->assertSessionHasErrors('generation');

    $generation = Generation::query()->sole();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_generation_id)->toBeNull()
        ->and($generation->actual_provider_cost_nanodollars)->toBeNull()
        ->and($generation->actual_user_charge_nanodollars)->toBeNull()
        ->and($generation->completed_at)->toBeNull()
        ->and($requests)->toHaveCount(3)
        ->and(array_column($requests, 0))->toBe(array_fill(0, 3, [$generation->idempotency_key]))
        ->and(array_unique(array_column($requests, 1)))->toHaveCount(1);
});

test('a stale submission response cannot regress a terminal generation or overwrite its charges', function (string $status) {
    $finalRates = array_replace($this->rates, ['output_tokens' => '41.00']);
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response($this->pricing),
        'https://pixelforge.test/v1/generations' => function (Request $request) use ($finalRates, $status) {
            Generation::query()->findOrFail(data_get($request->data(), 'metadata.generation_id'))->forceFill([
                'provider_generation_id' => 'gen_example',
                'status' => GenerationStatus::Succeeded,
                'rates' => $finalRates,
                'usage' => ['output_tokens' => 3],
                'image_url' => 'https://example.com/result.png',
                'completed_at' => now(),
                'actual_provider_cost_nanodollars' => 123_000,
                'actual_user_charge_nanodollars' => 153_750,
            ])->saveOrFail();

            return Http::response(array_replace($this->queued, [
                'status' => $status,
                'completed_at' => $status === 'failed' ? '2026-11-17T09:00:30Z' : null,
            ]), 202);
        },
    ]);

    $this->actingAs(User::factory()->create())->from(route('dashboard'))
        ->post(route('generations.store'), ['prompt' => 'Fox'])
        ->assertStatus(303)->assertSessionHasNoErrors();

    $generation = Generation::query()->sole();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_generation_id)->toBe('gen_example')
        ->and($generation->rates)->toBe($finalRates)
        ->and($generation->image_url)->toBe('https://example.com/result.png')
        ->and($generation->actual_provider_cost_nanodollars)->toBe(123_000)
        ->and($generation->actual_user_charge_nanodollars)->toBe(153_750);
})->with(['queued', 'failed']);

test('settles a terminal idempotent replay using its actual usage and frozen rates', function () {
    $rates = array_replace($this->rates, ['output_tokens' => '41.00']);
    $usage = ['input_text_tokens' => 2, 'input_image_tokens' => 0, 'output_tokens' => 11, 'reasoning_tokens' => 0];
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response($this->pricing),
        'https://pixelforge.test/v1/generations' => Http::sequence()
            ->pushFailedConnection()
            ->push(array_replace($this->queued, [
                'status' => 'succeeded',
                'rates' => $rates,
                'usage' => $usage,
                'image_url' => 'https://example.com/result.png',
                'image_url_expires_at' => '2026-11-18T09:00:30Z',
                'started_at' => '2026-11-17T09:00:05Z',
                'completed_at' => '2026-11-17T09:00:30Z',
            ]), 202, ['Idempotent-Replayed' => 'true']),
    ]);

    $this->actingAs(User::factory()->create())->from(route('dashboard'))
        ->post(route('generations.store'), ['prompt' => 'Fox'])
        ->assertStatus(303)->assertSessionHasNoErrors();

    $generation = Generation::query()->sole();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->rates)->toBe($rates)
        ->and($generation->usage)->toBe($usage)
        ->and($generation->actual_provider_cost_nanodollars)->toBe(461_000)
        ->and($generation->actual_user_charge_nanodollars)->toBe(576_250)
        ->and($generation->completed_at->toIso8601String())->toBe('2026-11-17T09:00:30+00:00');
});

test('missing provider credentials do not create a pending generation', function () {
    config(['services.pixelforge.key' => null]);
    Http::fake();

    $this->actingAs(User::factory()->create())->from(route('generations.index'))
        ->post(route('generations.store'), ['prompt' => 'Fox'])
        ->assertSessionHasErrors(['generation' => 'Image generation is temporarily unavailable.']);

    $this->assertDatabaseCount('generations', 0);
    Http::assertNothingSent();
});
