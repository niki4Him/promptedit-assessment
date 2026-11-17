<?php

use App\Actions\ApplyPixelforgeWebhook;
use App\Actions\RecoverGeneration;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test',
        'services.pixelforge.key' => 'test-key',
    ]);
    $this->travelTo(now()->startOfSecond());
    Http::preventStrayRequests();
    Sleep::fake();

    $this->user = User::factory()->create();
    $this->generation = $this->user->generations()->create([
        'idempotency_key' => Str::uuid()->toString(),
        'prompt' => 'Draw a fox',
        'size' => '1024x1024',
        'quality' => 'medium',
        'estimated_provider_cost_nanodollars' => 58_000_000,
        'estimated_user_charge_nanodollars' => 72_500_000,
    ])->forceFill(['created_at' => now()->subMinutes(2), 'provider_generation_id' => 'gen_recover']);
    $this->generation->saveOrFail();

    $this->snapshot = [
        'object' => 'generation',
        'id' => 'gen_recover',
        'status' => 'succeeded',
        'metadata' => ['generation_id' => (string) $this->generation->id],
        'rates' => [
            'input_text_tokens' => '0.001',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.001',
            'reasoning_tokens' => '12.50',
        ],
        'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 1, 'output_tokens' => 1, 'reasoning_tokens' => 1],
        'image_url' => 'https://example.com/result.png',
        'image_url_expires_at' => '2026-11-18T09:00:30Z',
        'created_at' => '2026-11-17T09:00:00Z',
        'started_at' => '2026-11-17T09:00:05Z',
        'completed_at' => '2026-11-17T09:00:30Z',
    ];
});

test('only the owner can recover a generation', function () {
    Http::fake();
    $url = route('generations.recover', $this->generation);

    $this->post($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->post($url)->assertNotFound();

    Http::assertNothingSent();
    expect($this->generation->refresh()->last_recovery_attempt_at)->toBeNull();
});

test('recovers a known provider ID via authenticated GET and settles exact amounts', function () {
    Http::fake(['https://pixelforge.test/v1/generations/gen_recover' => Http::response($this->snapshot)]);

    $this->actingAs($this->user)->from(route('generations.index'))->post(route('generations.recover', $this->generation))
        ->assertStatus(303)->assertRedirect(route('generations.index'))->assertSessionHasNoErrors();

    $generation = $this->generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->actual_provider_cost_nanodollars)->toBe(62_502)
        ->and($generation->actual_user_charge_nanodollars)->toBe(78_128)
        ->and($generation->rates)->toBe($this->snapshot['rates'])
        ->and($generation->usage)->toBe($this->snapshot['usage'])
        ->and($generation->image_url)->toBe($this->snapshot['image_url'])
        ->and($generation->image_url_expires_at->toIso8601String())->toBe('2026-11-18T09:00:30+00:00')
        ->and($generation->completed_at->toIso8601String())->toBe('2026-11-17T09:00:30+00:00');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.test/v1/generations/gen_recover'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
    Http::assertSentCount(1);
});

test('recovers a provider failure with zero charges', function () {
    $response = array_replace($this->snapshot, [
        'status' => 'failed',
        'rates' => null,
        'usage' => null,
        'error' => ['code' => 'generation_failed', 'message' => 'Could not generate the image.'],
    ]);
    Http::fake(['https://pixelforge.test/v1/generations/gen_recover' => Http::response($response)]);

    $this->actingAs($this->user)->from(route('generations.index'))->post(route('generations.recover', $this->generation))
        ->assertStatus(303)->assertSessionHasNoErrors();

    $generation = $this->generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->actual_provider_cost_nanodollars)->toBe(0)
        ->and($generation->actual_user_charge_nanodollars)->toBe(0)
        ->and($generation->provider_error_code)->toBe('generation_failed')
        ->and($generation->provider_error_message)->toBe('Could not generate the image.');
});

test('unknown provider IDs replay exactly the original request, including reference omission', function (?array $references) {
    $this->generation->delete();
    $requests = [];
    $price = [
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => $this->snapshot['rates'],
        'tokens' => [
            'characters_per_text_token' => 4,
            'per_reference_image' => 765,
            'output' => ['medium' => ['1024x1024' => 1100]],
            'reasoning' => ['medium' => ['min' => 200, 'max' => 2000]],
        ],
    ];
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response($price),
        'https://pixelforge.test/v1/generations' => function (Request $request) use (&$requests) {
            $requests[] = [$request->header('Idempotency-Key'), $request->body()];
            if (count($requests) <= 3) {
                return Http::failedConnection();
            }

            return Http::response(array_replace($this->snapshot, [
                'metadata' => ['generation_id' => data_get($request->data(), 'metadata.generation_id')],
            ]), 202, ['Idempotent-Replayed' => 'true']);
        },
    ]);

    $payload = ['prompt' => 'Draw a U+1F98A 🦊', 'size' => '1024x1024', 'quality' => 'medium'];
    if ($references !== null) {
        $payload['reference_images'] = $references;
    }
    $this->actingAs($this->user)->from(route('generations.index'))->post(route('generations.store'), $payload)
        ->assertSessionHasErrors('generation');
    $generation = Generation::query()->sole();
    expect($generation->provider_generation_id)->toBeNull();

    $this->travel(60)->seconds();
    $this->from(route('generations.index'))->post(route('generations.recover', $generation))
        ->assertStatus(303)->assertSessionHasNoErrors();

    expect($requests)->toHaveCount(4)
        ->and($requests)->toBe(array_fill(0, 4, $requests[0]))
        ->and($requests[0][0])->toBe([$generation->idempotency_key])
        ->and(json_decode($requests[0][1], true))->toBe([...$payload, 'metadata' => ['generation_id' => (string) $generation->id]])
        ->and($generation->refresh()->provider_generation_id)->toBe('gen_recover')
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->actual_user_charge_nanodollars)->toBe(78_128);
})->with([
    'omitted references' => [null],
    'empty references' => [[]],
    'ordered references' => [['https://example.com/one.png', 'http://example.com/two.png']],
]);

test('missing or invalid provider snapshots leave the generation pending', function (int $status, string $kind) {
    $response = match ($kind) {
        'missing' => ['error' => ['code' => 'not_found']],
        'mismatched ID' => array_replace($this->snapshot, ['id' => 'gen_other']),
        'invalid status' => array_replace($this->snapshot, ['status' => 'mystery']),
        'mismatched metadata' => array_replace($this->snapshot, ['metadata' => ['generation_id' => '999999']]),
        'invalid usage' => array_replace($this->snapshot, ['usage' => ['output_tokens' => 100]]),
    };
    Http::fake(['https://pixelforge.test/v1/generations/gen_recover' => Http::response($response, $status)]);

    $this->actingAs($this->user)->from(route('generations.index'))->post(route('generations.recover', $this->generation))
        ->assertSessionHasErrors('recovery');

    $generation = $this->generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_generation_id)->toBe('gen_recover')
        ->and($generation->actual_user_charge_nanodollars)->toBeNull()
        ->and($generation->last_recovery_attempt_at)->not->toBeNull();

    Http::assertSentCount(1);
})->with([
    [404, 'missing'],
    [200, 'mismatched ID'],
    [200, 'invalid status'],
    [200, 'mismatched metadata'],
    [200, 'invalid usage'],
]);

test('a recovery is claimed before the provider call and blocks overlapping recoveries', function () {
    $this->generation->provider_generation_id = null;
    $this->generation->saveOrFail();
    Http::fake([
        'https://pixelforge.test/v1/generations' => function () {
            $pending = $this->generation->fresh();
            expect($pending->last_recovery_attempt_at)->not->toBeNull();
            expect(fn () => app(RecoverGeneration::class)->handle($pending))->toThrow(ValidationException::class);

            return Http::response(array_replace($this->snapshot, ['status' => 'processing']), 202);
        },
    ]);

    $this->actingAs($this->user)->from(route('generations.index'))->post(route('generations.recover', $this->generation))
        ->assertStatus(303)->assertSessionHasNoErrors();

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($this->generation->provider_generation_id)->toBe('gen_recover');
    Http::assertSentCount(1);
});

test('cooldown survives requests and is enforced after provider errors', function () {
    Http::fake([
        'https://pixelforge.test/v1/generations/gen_recover' => Http::sequence()
            ->push(['error' => ['code' => 'not_found']], 404)
            ->push(array_replace($this->snapshot, ['status' => 'processing'])),
    ]);

    $url = route('generations.recover', $this->generation);
    $this->actingAs($this->user)->from(route('generations.index'))->post($url)->assertSessionHasErrors('recovery');
    $this->travel(59)->seconds();
    $this->from(route('generations.index'))->post($url)->assertSessionHasErrors('recovery');
    Http::assertSentCount(1);

    $this->travel(1)->seconds();
    $this->from(route('generations.index'))->post($url)->assertStatus(303)->assertSessionHasNoErrors();
    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Processing);
    Http::assertSentCount(2);
});

test('a newly created pending record cannot be replayed while initial submission may still be in flight', function () {
    Http::fake();
    $this->generation->forceFill(['created_at' => now(), 'provider_generation_id' => null])->saveOrFail();

    $this->actingAs($this->user)->from(route('generations.index'))->post(route('generations.recover', $this->generation))
        ->assertSessionHasErrors('recovery');

    Http::assertNothingSent();
});

test('an early webhook during replay wins over stale or conflicting provider responses', function (string $providerId) {
    $this->generation->provider_generation_id = null;
    $this->generation->saveOrFail();

    Http::fake([
        'https://pixelforge.test/v1/generations' => function () use ($providerId) {
            app(ApplyPixelforgeWebhook::class)->handle([
                'id' => 'evt_race',
                'type' => 'generation.succeeded',
                'data' => ['object' => $this->snapshot],
            ]);

            return Http::response(array_replace($this->snapshot, ['id' => $providerId, 'status' => 'queued']), 202);
        },
    ]);

    $response = $this->actingAs($this->user)->from(route('generations.index'))->post(route('generations.recover', $this->generation));
    if ($providerId === 'gen_recover') {
        $response->assertStatus(303)->assertSessionHasNoErrors();
    } else {
        $response->assertSessionHasErrors('recovery');
    }

    $generation = $this->generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_generation_id)->toBe('gen_recover')
        ->and($generation->actual_provider_cost_nanodollars)->toBe(62_502)
        ->and($generation->actual_user_charge_nanodollars)->toBe(78_128);
    $this->assertDatabaseHas('pixelforge_webhook_events', ['id' => 'evt_race']);
})->with(['gen_recover', 'gen_conflict']);

test('terminal or already charged generations are never sent to the provider again', function (GenerationStatus $status) {
    Http::fake();
    $this->generation->forceFill([
        'status' => $status,
        'actual_provider_cost_nanodollars' => 0,
        'actual_user_charge_nanodollars' => 0,
        'provider_generation_id' => null,
    ])->saveOrFail();
    $before = $this->generation->refresh()->getRawOriginal();

    $this->actingAs($this->user)->from(route('generations.index'))->post(route('generations.recover', $this->generation))
        ->assertStatus(303)->assertSessionHasNoErrors();

    expect($this->generation->refresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with([GenerationStatus::Succeeded, GenerationStatus::Failed, GenerationStatus::Processing]);

test('manual recovery honors a Retry-After that exceeds the local cooldown', function () {
    Http::fake([
        'https://pixelforge.test/v1/generations/gen_recover' => Http::sequence()
            ->push(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120'])
            ->push($this->snapshot),
    ]);

    $url = route('generations.recover', $this->generation);
    $this->actingAs($this->user)->from(route('generations.index'))->post($url)
        ->assertSessionHasErrors(['recovery' => 'Pixelforge asked to wait 120 seconds before trying again.']);

    $this->travel(119)->seconds();
    $this->from(route('generations.index'))->post($url)->assertSessionHasErrors('recovery');
    Http::assertSentCount(1);

    $this->travel(1)->seconds();
    $this->from(route('generations.index'))->post($url)->assertStatus(303)->assertSessionHasNoErrors();
    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
    Http::assertSentCount(2);
});

test('a provider wait from initial submission also blocks manual replay', function () {
    $this->generation->delete();
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response([
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => $this->snapshot['rates'],
            'tokens' => [
                'characters_per_text_token' => 4,
                'per_reference_image' => 765,
                'output' => ['medium' => ['1024x1024' => 1100]],
                'reasoning' => ['medium' => ['min' => 200, 'max' => 2000]],
            ],
        ]),
        'https://pixelforge.test/v1/generations' => Http::response(
            ['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120'],
        ),
    ]);

    $this->actingAs($this->user)->from(route('generations.index'))->post(route('generations.store'), ['prompt' => 'Fox'])
        ->assertSessionHasErrors('generation');

    $generation = Generation::query()->sole();
    $this->travel(60)->seconds();
    $this->from(route('generations.index'))->post(route('generations.recover', $generation))->assertSessionHasErrors('recovery');

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->last_recovery_attempt_at)->toBeNull();
    Http::assertSentCount(2);
});
