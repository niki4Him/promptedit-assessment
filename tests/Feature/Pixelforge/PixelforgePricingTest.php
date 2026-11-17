<?php

use App\Services\Pixelforge\PixelforgePricing;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test']);
    Http::preventStrayRequests();

    $this->rates = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];
});

test('estimates from endpoint rules for different qualities, sizes and references', function (
    string $quality, string $size, int $references, int $cost, int $charge,
) {
    Http::fake(['https://pixelforge.test/v1/pricing' => Http::response([
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => $this->rates,
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420],
                'medium' => ['1024x1536' => 1650],
                'high' => ['1536x1024' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ])]);

    $pricing = app(PixelforgePricing::class);

    expect($pricing->estimate('é猫🙂ab', $size, $quality, $references))->toBe([
        'provider_cost_nanodollars' => $cost,
        'user_charge_nanodollars' => $charge,
    ]);

    Http::assertSentCount(1);
})->with([
    'low square' => ['low', '1024x1024', 0, 14_335_000, 17_918_750],
    'low landscape' => ['low', '1536x1024', 0, 19_935_000, 24_918_750],
    'medium portrait with one reference' => ['medium', '1024x1536', 1, 87_410_000, 109_262_500],
    'high landscape with four references' => ['high', '1536x1024', 4, 344_860_000, 431_075_000],
]);

test('uses live rules for the estimate and actual usage at the generation frozen rates', function () {
    Http::fake(['https://pixelforge.test/v1/pricing' => Http::response([
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => array_replace($this->rates, ['output_tokens' => '41.00']),
        'tokens' => [
            'characters_per_text_token' => 2,
            'per_reference_image' => 100,
            'output' => ['low' => ['1024x1024' => 10]],
            'reasoning' => ['low' => ['min' => 1, 'max' => 4]],
        ],
    ])]);

    $pricing = app(PixelforgePricing::class);
    $estimate = $pricing->estimate('hello', '1024x1024', 'low', 1);
    $actual = $pricing->actual([
        'status' => 'succeeded',
        'rates' => $this->rates,
        'usage' => [
            'input_text_tokens' => 4,
            'input_image_tokens' => 100,
            'output_tokens' => 10,
            'reasoning_tokens' => 3,
            'total_tokens' => 117,
        ],
    ]);

    expect($estimate)->toBe(['provider_cost_nanodollars' => 1_450_000, 'user_charge_nanodollars' => 1_812_500])
        ->and($actual)->toBe(['provider_cost_nanodollars' => 1_457_500, 'user_charge_nanodollars' => 1_821_875]);

    Http::assertSentCount(1);
});

test('keeps exact nanodollars and rounds the markup once per generation', function (int $tokens, int $charge) {
    $cost = app(PixelforgePricing::class)->actual([
        'status' => 'succeeded',
        'rates' => array_replace($this->rates, ['input_text_tokens' => '0.001000']),
        'usage' => [
            'input_text_tokens' => $tokens,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ],
    ]);

    expect($cost)->toBe(['provider_cost_nanodollars' => $tokens, 'user_charge_nanodollars' => $charge]);
})->with([
    'round down below half' => [1, 1],
    'round half up' => [2, 3],
    'round up above half' => [3, 4],
    'exact quarter' => [4, 5],
]);

test('reconciles exactly with the documented usage total', function () {
    $cost = app(PixelforgePricing::class)->actual([
        'status' => 'succeeded',
        'rates' => $this->rates,
        'usage' => [
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
        ],
    ]);

    $usageCostUsd = '0.169080000';
    expect($cost['provider_cost_nanodollars'])->toBe((int) str_replace('.', '', $usageCostUsd));
});

test('failed generations cost zero and pending generations have no final cost', function () {
    $pricing = app(PixelforgePricing::class);

    expect($pricing->actual(['status' => 'failed', 'usage' => null]))
        ->toBe(['provider_cost_nanodollars' => 0, 'user_charge_nanodollars' => 0])
        ->and(fn () => $pricing->actual(['status' => 'processing', 'usage' => null]))
        ->toThrow(InvalidArgumentException::class);
});
