<?php

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test',
        'services.pixelforge.key' => 'test-key',
    ]);

    Http::preventStrayRequests();
    Sleep::fake();
});

test('pricing is fetched without credentials', function () {
    Http::fake(['https://pixelforge.test/v1/pricing' => Http::response(['object' => 'pricing'])]);

    expect(app(PixelforgeClient::class)->pricing())->toBe(['object' => 'pricing']);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.test/v1/pricing'
        && ! $request->hasHeader('Authorization'));
});

test('generation sends bearer authentication, the caller key and the documented payload', function () {
    Http::fake(['https://pixelforge.test/v1/generations' => Http::response(['status' => 'queued'], 202)]);

    $generation = app(PixelforgeClient::class)->generate(
        'order-1', 'Draw a fox', '1536x1024', 'high', ['https://example.com/style.png'], ['1' => 'order'],
    );

    expect($generation)->toBe(['status' => 'queued']);

    Http::assertSent(function (Request $request) {
        $payload = json_decode($request->body());

        return $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->hasHeader('Idempotency-Key', 'order-1')
            && $payload->prompt === 'Draw a fox'
            && $payload->size === '1536x1024'
            && $payload->quality === 'high'
            && $payload->reference_images === ['https://example.com/style.png']
            && $payload->metadata instanceof stdClass
            && $payload->metadata->{'1'} === 'order';
    });
});

test('omits optional fields when absent', function () {
    Http::fake(['https://pixelforge.test/v1/generations' => Http::response(['status' => 'queued'], 202)]);

    app(PixelforgeClient::class)->generate('order-1', 'Draw a fox', '1024x1024', 'low');

    Http::assertSent(fn (Request $request) => $request->data() === [
        'prompt' => 'Draw a fox',
        'size' => '1024x1024',
        'quality' => 'low',
    ]);
});

test('rejects a blank idempotency key before sending a generation', function () {
    Http::fake();

    expect(fn () => app(PixelforgeClient::class)->generate(' ', 'Fox', '1024x1024', 'low'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

test('preserves provider errors without retrying terminal responses', function (int $status, string $code, string $header) {
    $error = ['error' => [
        'type' => 'provider_error',
        'code' => $code,
        'message' => 'Provider message',
        'param' => 'size',
        'errors' => ['size' => ['Provider details']],
    ]];
    Http::fake(['https://pixelforge.test/v1/generations' => Http::response($error, $status, ['Retry-After' => $header])]);

    $exception = null;
    try {
        app(PixelforgeClient::class)->generate('order-1', 'Draw a fox', '1024x1024', 'low');
    } catch (RequestException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(RequestException::class);
    expect($exception->response->status())->toBe($status)
        ->and($exception->response->json())->toBe($error)
        ->and($exception->response->header('Retry-After'))->toBe($header);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([
    'invalid key' => [401, 'invalid_api_key', ''],
    'content policy' => [400, 'content_policy_violation', ''],
    'idempotency conflict' => [409, 'idempotency_key_reused', ''],
    'validation error' => [422, 'validation_failed', ''],
    'daily quota' => [429, 'daily_limit_exceeded', '2'],
    'long rate limit' => [429, 'rate_limit_exceeded', '60'],
    'unknown server failure' => [500, 'internal_error', '2'],
]);

test('honors retry-after, backs off and reuses the exact generation request', function () {
    Http::fake([
        'https://pixelforge.test/v1/generations' => Http::sequence()
            ->push(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2'])
            ->push(['error' => ['code' => 'concurrency_limit_exceeded']], 429, ['Retry-After' => '5'])
            ->push(['id' => 'gen_example', 'status' => 'queued'], 202),
    ]);

    expect(app(PixelforgeClient::class)->generate('order-1', 'Draw a fox', '1024x1024', 'low'))
        ->toBe(['id' => 'gen_example', 'status' => 'queued']);

    Http::assertSentCount(3);
    $requests = Http::recorded()->map(fn (array $pair) => $pair[0]);
    $requests->each(function (Request $request) use ($requests) {
        expect($request->header('Idempotency-Key'))->toBe(['order-1'])
            ->and($request->body())->toBe($requests->first()->body());
    });
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(5250)->milliseconds()]);
});

test('stops after three attempts when Pixelforge remains overloaded', function () {
    Http::fake([
        'https://pixelforge.test/v1/generations' => Http::response(
            ['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2'],
        ),
    ]);

    expect(fn () => app(PixelforgeClient::class)->generate('order-1', 'Fox', '1024x1024', 'low'))
        ->toThrow(RequestException::class);

    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2250)->milliseconds()]);
});

test('retries an uncertain connection failure with the same key and payload', function () {
    $attempts = [];
    Http::fake(['https://pixelforge.test/v1/generations' => function (Request $request) use (&$attempts) {
        $attempts[] = [$request->header('Idempotency-Key'), $request->body()];

        return count($attempts) === 1
            ? Http::failedConnection()
            : Http::response(['id' => 'gen_example', 'status' => 'processing'], 202, ['Idempotent-Replayed' => 'true']);
    }]);

    expect(app(PixelforgeClient::class)->generate('order-1', 'Fox', '1024x1024', 'low'))
        ->toBe(['id' => 'gen_example', 'status' => 'processing'])
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[1])->toBe($attempts[0]);
    Sleep::assertSequence([Sleep::for(250)->milliseconds()]);
});
