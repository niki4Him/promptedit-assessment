<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    $this->secret = 'test-webhook-secret';
    config(['services.pixelforge.webhook_secret' => $this->secret]);
    Http::preventStrayRequests();

    $this->generation = User::factory()->create()->generations()->create([
        'idempotency_key' => Str::uuid()->toString(),
        'prompt' => 'Draw a fox',
        'size' => '1024x1024',
        'quality' => 'medium',
        'estimated_provider_cost_nanodollars' => 58_000_000,
        'estimated_user_charge_nanodollars' => 72_500_000,
    ]);

    $this->snapshot = [
        'object' => 'generation',
        'id' => 'gen_example',
        'status' => 'succeeded',
        'metadata' => ['generation_id' => (string) $this->generation->getKey()],
        'rates' => [
            'input_text_tokens' => '0.001',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.001',
            'reasoning_tokens' => '12.50',
        ],
        'usage' => [
            'input_text_tokens' => 1,
            'input_image_tokens' => 1,
            'output_tokens' => 1,
            'reasoning_tokens' => 1,
            'total_tokens' => 4,
        ],
        'image_url' => 'https://example.com/result.png?signature=example',
        'image_url_expires_at' => '2026-11-18T09:00:30Z',
        'created_at' => '2026-11-17T09:00:00Z',
        'started_at' => '2026-11-17T09:00:05Z',
        'completed_at' => '2026-11-17T09:00:30Z',
    ];

    $this->signedRequest = function (array|string $event, int $offset = 0, ?Closure $alterHeader = null, string $suffix = ''): TestResponse {
        $body = is_array($event) ? json_encode($event, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) : $event;
        $time = now()->getTimestamp() + $offset;
        $digest = hash_hmac('sha256', $time.'.'.$body, $this->secret);
        $header = "t={$time},v1={$digest}";

        return $this->call('POST', route('webhooks.pixelforge'), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $alterHeader ? $alterHeader($header) : $header,
        ], content: $body.$suffix);
    };

    $this->event = function (string $id = 'evt_success', string $status = 'succeeded', ?array $snapshot = null): array {
        return [
            'object' => 'event',
            'id' => $id,
            'type' => 'generation.'.$status,
            'created_at' => '2026-11-17T09:00:30Z',
            'data' => ['object' => array_replace($this->snapshot, $snapshot ?? [], ['status' => $status])],
        ];
    };
});

test('accepts a signed ping anonymously and deduplicates fresh deliveries', function () {
    $event = ['object' => 'event', 'id' => 'evt_ping', 'type' => 'ping'];

    ($this->signedRequest)($event)->assertNoContent();
    $this->travel(10)->seconds();
    ($this->signedRequest)($event)->assertNoContent();

    $this->assertDatabaseCount('pixelforge_webhook_events', 1);
    $this->assertDatabaseHas('pixelforge_webhook_events', ['id' => 'evt_ping']);
});

test('rejects invalid signatures before decoding the event', function (Closure $alterHeader) {
    ($this->signedRequest)('{invalid JSON', alterHeader: $alterHeader)->assertNoContent(400);

    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
})->with([
    'empty header' => [fn (string $header) => ''],
    'wrong HMAC' => [fn (string $header) => substr($header, 0, -64).str_repeat('0', 64)],
    'duplicate version' => [fn (string $header) => $header.','.explode(',', $header)[1]],
    'duplicate timestamp' => [fn (string $header) => explode(',', $header)[0].','.explode(',', $header)[0]],
    'noninteger timestamp' => [fn (string $header) => 't=12abc,'.explode(',', $header)[1]],
    'timestamp beyond integer range' => [fn (string $header) => 't=9999999999999999999,'.explode(',', $header)[1]],
]);

test('a change to raw whitespace invalidates the signature', function () {
    ($this->signedRequest)(($this->event)(), suffix: "\n")->assertNoContent(400);

    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
});

test('rejects timestamps outside the tolerance in either direction', function (int $offset) {
    ($this->signedRequest)(($this->event)(), $offset)->assertNoContent(400);

    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
})->with([-301, 301]);

test('accepts the tolerance boundary and either order of signature fields', function () {
    ($this->signedRequest)(($this->event)(), -300, fn (string $header) => implode(',', array_reverse(explode(',', $header))))
        ->assertNoContent();
});

test('fails closed if the webhook secret is not configured', function () {
    config(['services.pixelforge.webhook_secret' => null]);

    ($this->signedRequest)(($this->event)())->assertNoContent(400);

    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
});

test('rejects malformed signed events', function (string $kind) {
    $event = ($this->event)();

    $invalid = match ($kind) {
        'json' => '{invalid JSON',
        'unsupported type' => array_replace($event, ['type' => 'generation.queued']),
        'missing data' => array_diff_key($event, ['data' => true]),
        'type status mismatch' => array_replace($event, ['type' => 'generation.failed']),
        'bad local ID' => ($this->event)(snapshot: ['metadata' => ['generation_id' => '0']]),
        'missing expiry' => ($this->event)(snapshot: ['image_url_expires_at' => null]),
    };

    ($this->signedRequest)($invalid)->assertNoContent(422);
    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
})->with(['json', 'unsupported type', 'missing data', 'type status mismatch', 'bad local ID', 'missing expiry']);

test('correlates an early processing event by local metadata and records the provider ID', function () {
    expect($this->generation->provider_generation_id)->toBeNull();
    $event = ($this->event)('evt_processing', 'processing');

    ($this->signedRequest)($event)->assertNoContent();

    $generation = $this->generation->refresh();
    expect($generation->provider_generation_id)->toBe('gen_example')
        ->and($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->started_at->toIso8601String())->toBe('2026-11-17T09:00:05+00:00')
        ->and($generation->actual_user_charge_nanodollars)->toBeNull();
});

test('finalizes a queued generation exactly using the frozen rates and handles repeated delivery', function () {
    $event = ($this->event)();
    ($this->signedRequest)($event)->assertNoContent();

    $generation = $this->generation->refresh();
    expect($generation->provider_generation_id)->toBe('gen_example')
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->actual_provider_cost_nanodollars)->toBe(62_502)
        ->and($generation->actual_user_charge_nanodollars)->toBe(78_128)
        ->and($generation->rates)->toBe($this->snapshot['rates'])
        ->and($generation->usage)->toBe($this->snapshot['usage'])
        ->and($generation->image_url)->toBe($this->snapshot['image_url'])
        ->and($generation->image_url_expires_at->toIso8601String())->toBe('2026-11-18T09:00:30+00:00')
        ->and($generation->completed_at->toIso8601String())->toBe('2026-11-17T09:00:30+00:00');

    $before = $generation->getRawOriginal();
    $this->travel(1)->minutes();

    foreach (range(1, 5) as $attempt) {
        ($this->signedRequest)($event)->assertNoContent();
    }

    expect($generation->refresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('pixelforge_webhook_events', 1);
});

test('stale processing and terminal events with fresh IDs leave a completed generation untouched', function () {
    ($this->signedRequest)(($this->event)())->assertNoContent();

    $before = $this->generation->refresh()->getRawOriginal();
    $this->travel(1)->minutes();
    ($this->signedRequest)(($this->event)('evt_late_processing', 'processing'))->assertNoContent();
    ($this->signedRequest)(($this->event)('evt_second_success', snapshot: ['rates' => ['output_tokens' => 'invalid']]))
        ->assertNoContent();
    ($this->signedRequest)(($this->event)('evt_late_failed', 'failed', ['error' => ['code' => 'generation_failed', 'message' => 'Stale']]))
        ->assertNoContent();

    expect($this->generation->refresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('pixelforge_webhook_events', 4);
});

test('finalizes failure at zero after processing and keeps failure terminal', function () {
    ($this->signedRequest)(($this->event)('evt_processing', 'processing'))->assertNoContent();
    ($this->signedRequest)(($this->event)('evt_failure', 'failed', [
        'usage' => null,
        'image_url' => null,
        'image_url_expires_at' => null,
        'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
    ]))->assertNoContent();

    $generation = $this->generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->actual_provider_cost_nanodollars)->toBe(0)
        ->and($generation->actual_user_charge_nanodollars)->toBe(0)
        ->and($generation->provider_error_code)->toBe('generation_failed')
        ->and($generation->provider_error_message)->toBe('The model could not produce an image.')
        ->and($generation->completed_at->toIso8601String())->toBe('2026-11-17T09:00:30+00:00');

    $before = $generation->getRawOriginal();
    ($this->signedRequest)(($this->event)())->assertNoContent();
    expect($generation->refresh()->getRawOriginal())->toBe($before);
});

test('an unknown local generation is not recorded as processed', function () {
    $event = ($this->event)(snapshot: ['metadata' => ['generation_id' => '99999999']]);

    ($this->signedRequest)($event)->assertNotFound();

    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
});

test('pricing failure rolls back the receipt and allows successful redelivery', function () {
    $event = ($this->event)(snapshot: ['rates' => array_replace($this->snapshot['rates'], ['output_tokens' => '0.0001'])]);
    ($this->signedRequest)($event)->assertNoContent(422);

    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
    $generation = $this->generation->refresh();
    expect($generation->provider_generation_id)->toBeNull()
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->actual_user_charge_nanodollars)->toBeNull();

    ($this->signedRequest)(($this->event)())->assertNoContent();

    $this->assertDatabaseCount('pixelforge_webhook_events', 1);
    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

test('rolls back the receipt if the database rejects a provider ID collision', function () {
    $other = Generation::query()->findOrFail($this->generation->getKey());
    $other->provider_generation_id = 'gen_other';
    $other->saveOrFail();
    $second = $other->user->generations()->create([
        'idempotency_key' => Str::uuid()->toString(),
        'prompt' => 'Draw a cat',
        'size' => '1024x1024',
        'quality' => 'medium',
        'estimated_provider_cost_nanodollars' => 1,
        'estimated_user_charge_nanodollars' => 1,
    ]);
    $second->provider_generation_id = 'gen_example';
    $second->saveOrFail();

    ($this->signedRequest)(($this->event)())->assertNoContent(422);
    $this->assertDatabaseCount('pixelforge_webhook_events', 0);

    $other->provider_generation_id = null;
    $other->saveOrFail();

    ($this->signedRequest)(($this->event)())->assertNoContent(409);
    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
    expect($other->refresh()->provider_generation_id)->toBeNull();
});

test('the receipt ID has a database uniqueness constraint', function () {
    $row = ['id' => 'evt_unique', 'processed_at' => now()];
    DB::table('pixelforge_webhook_events')->insert($row);

    expect(DB::table('pixelforge_webhook_events')->insertOrIgnore($row))->toBe(0);
    $this->assertDatabaseCount('pixelforge_webhook_events', 1);
});
