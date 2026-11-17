<?php

use App\Enums\GenerationStatus;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test']);
    Http::preventStrayRequests();

    $this->generationAttributes = fn () => [
        'idempotency_key' => Str::uuid()->toString(),
        'prompt' => 'Draw a fox',
        'size' => '1024x1024',
        'quality' => 'medium',
        'estimated_provider_cost_nanodollars' => 57_755_000,
        'estimated_user_charge_nanodollars' => 72_193_750,
    ];

    $this->pricing = [
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'per_reference_image' => 765,
            'output' => ['medium' => ['1024x1024' => 1100]],
            'reasoning' => ['medium' => ['min' => 200, 'max' => 2000]],
        ],
    ];
});

test('history and estimate require authentication', function () {
    Http::fake();

    $this->get(route('generations.index'))->assertRedirect(route('login'));
    $this->postJson(route('generations.estimate'), ['prompt' => 'Fox'])->assertUnauthorized();

    Http::assertNothingSent();
});

test('history shows the signed-in user’s generations newest first and keeps monetary values exact', function () {
    Http::fake();
    $user = User::factory()->create();
    $old = $user->generations()->create(($this->generationAttributes)())->forceFill([
        'status' => GenerationStatus::Failed,
        'provider_error_message' => 'Sensitive provider response details.',
        'actual_user_charge_nanodollars' => 0,
        'created_at' => now()->subDay(),
    ]);
    $old->saveOrFail();
    $new = $user->generations()->create(($this->generationAttributes)())->forceFill([
        'prompt' => 'Draw a lighthouse',
        'status' => GenerationStatus::Succeeded,
        'estimated_user_charge_nanodollars' => 9_007_199_254_740_993,
        'actual_user_charge_nanodollars' => 9_007_199_254_740_995,
        'image_url' => 'https://example.com/result.png',
        'image_url_expires_at' => now()->addHour(),
    ]);
    $new->saveOrFail();
    $other = User::factory()->create();
    $other->generations()->create(($this->generationAttributes)())->forceFill(['prompt' => 'Private prompt'])->saveOrFail();

    $this->actingAs($user)->get(route('generations.index', ['user_id' => $other->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Generations')
            ->has('history.data', 2)
            ->where('history.data.0.id', (string) $new->id)
            ->where('history.data.0.prompt', 'Draw a lighthouse')
            ->where('history.data.0.status', 'succeeded')
            ->where('history.data.0.estimated_user_charge_nanodollars', '9007199254740993')
            ->where('history.data.0.actual_user_charge_nanodollars', '9007199254740995')
            ->where('history.data.0.image_url', 'https://example.com/result.png')
            ->where('history.data.0.created_at', $new->created_at->toIso8601String())
            ->missing('history.data.0.idempotency_key')
            ->missing('history.data.0.actual_provider_cost_nanodollars')
            ->where('history.data.1.id', (string) $old->id)
            ->where('history.data.1.actual_user_charge_nanodollars', '0')
            ->where('history.data.1.error_message', 'Pixelforge could not generate the image.'));

    Http::assertNothingSent();
});

test('history partial reloads reflect finalization and omit expired image URLs', function () {
    Http::fake();
    $user = User::factory()->create();
    $generation = $user->generations()->create(($this->generationAttributes)());

    $this->actingAs($user)->get(route('generations.index'))->assertInertia(function (Assert $page) use ($generation) {
        $page->component('Generations')
            ->where('history.data.0.status', 'queued')
            ->where('history.data.0.actual_user_charge_nanodollars', null);

        $generation->forceFill([
            'status' => GenerationStatus::Succeeded,
            'actual_user_charge_nanodollars' => 78_128,
            'image_url' => 'https://example.com/expired.png',
            'image_url_expires_at' => now()->subSecond(),
        ])->saveOrFail();

        $page->reloadOnly('history', fn (Assert $partial) => $partial
            ->where('history.data.0.status', 'succeeded')
            ->where('history.data.0.actual_user_charge_nanodollars', '78128')
            ->where('history.data.0.image_expired', true)
            ->where('history.data.0.image_url', null)
            ->missing('auth'));
    });

    Http::assertNothingSent();
});

test('new users receive empty history and large histories are paginated', function () {
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('generations.index'))->assertInertia(
        fn (Assert $page) => $page->component('Generations')
            ->where('history.data', [])
            ->where('history.previous', null)
            ->where('history.next', null),
    );

    foreach (range(1, 13) as $number) {
        $user->generations()->create(array_replace(($this->generationAttributes)(), ['prompt' => 'Prompt '.$number]));
    }

    $this->get(route('generations.index'))->assertInertia(
        fn (Assert $page) => $page->has('history.data', 12)
            ->where('history.data.0.prompt', 'Prompt 13')
            ->where('history.next', route('generations.index', ['history' => 2])),
    );
    $this->get(route('generations.index', ['history' => 2]))->assertInertia(
        fn (Assert $page) => $page->has('history.data', 1)
            ->where('history.data.0.prompt', 'Prompt 1')
            ->where('history.previous', route('generations.index', ['history' => 1])),
    );
});

test('estimates reuse live provider rates during the preview cache window', function () {
    Http::fake(['https://pixelforge.test/v1/pricing' => Http::response($this->pricing)]);
    $this->actingAs(User::factory()->create());

    $this->postJson(route('generations.estimate'), ['prompt' => 'Fox'])
        ->assertOk()
        ->assertExactJson(['estimated_user_charge_nanodollars' => '72193750']);

    $this->postJson(route('generations.estimate'), [
        'prompt' => 'Draw a fox',
        'reference_images' => ['https://example.com/style.png'],
    ])->assertOk()
        ->assertExactJson(['estimated_user_charge_nanodollars' => '81768750']);

    Http::assertSentCount(1);
    $this->assertDatabaseCount('generations', 0);

    $this->travel(61)->seconds();

    $this->postJson(route('generations.estimate'), ['prompt' => 'Fox'])->assertOk();
    Http::assertSentCount(2);
});

test('invalid estimates return field errors without calling the provider', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())->postJson(route('generations.estimate'), [
        'prompt' => str_repeat('猫', 4001),
        'size' => '1x1',
        'quality' => 'ultra',
        'reference_images' => ['ftp://example.com/image.png'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['prompt', 'size', 'quality', 'reference_images.0']);

    Http::assertNothingSent();
});

test('unavailable or malformed preview pricing returns a clear unavailable response', function (int $status, array $pricingOverrides) {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(array_replace($this->pricing, $pricingOverrides), $status),
    ]);

    $this->actingAs(User::factory()->create())->postJson(route('generations.estimate'), ['prompt' => 'Fox'])
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Image pricing is temporarily unavailable.');

    Http::assertSentCount(1);
})->with([
    'provider outage' => [500, []],
    'malformed rates' => [200, ['rates' => 'not a rates object']],
]);
