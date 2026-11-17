<?php

namespace App\Services\Pixelforge;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use OverflowException;
use UnexpectedValueException;

final class PixelforgePricing
{
    private const TOKEN_TYPES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * Point estimate using baseline text usage and the midpoint of the reasoning range, rounded down.
     * Text variance and reasoning variability mean actual usage can differ.
     *
     * @return array{provider_cost_nanodollars: int, user_charge_nanodollars: int}
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImageCount = 0, bool $useCachedPricing = false): array
    {
        if ($referenceImageCount < 0) {
            throw new InvalidArgumentException('Reference image count cannot be negative.');
        }

        $pricing = $useCachedPricing
            ? Cache::remember('pixelforge:preview-pricing', 60, $this->client->pricing(...))
            : $this->client->pricing();

        if (($pricing['currency'] ?? null) !== 'usd' || ($pricing['unit'] ?? null) !== 'per_1m_tokens') {
            throw new UnexpectedValueException('Expected Pixelforge pricing in USD per million tokens.');
        }

        $tokens = $pricing['tokens'] ?? null;
        $rates = $pricing['rates'] ?? null;

        if (! is_array($tokens) || ! is_array($rates)) {
            throw new UnexpectedValueException('Expected Pixelforge token estimates and rates.');
        }

        $charactersPerToken = $this->nonNegativeInteger($tokens['characters_per_text_token'] ?? null);

        if ($charactersPerToken === 0) {
            throw new UnexpectedValueException('Characters per text token must be positive.');
        }

        $output = $tokens['output'][$quality][$size] ?? null;
        $reasoning = $tokens['reasoning'][$quality] ?? [];

        if ($output === null || ! is_array($reasoning) || $reasoning === []) {
            throw new InvalidArgumentException('Size and quality must be present in Pixelforge pricing.');
        }

        $length = mb_strlen($prompt, 'UTF-8');
        $minReasoning = $this->nonNegativeInteger($reasoning['min'] ?? null);
        $maxReasoning = $this->nonNegativeInteger($reasoning['max'] ?? null);

        if ($minReasoning > $maxReasoning) {
            throw new UnexpectedValueException('Invalid Pixelforge reasoning token range.');
        }

        return $this->cost([
            'input_text_tokens' => intdiv($length, $charactersPerToken) + (int) ($length % $charactersPerToken !== 0),
            'input_image_tokens' => $this->multiply($referenceImageCount, $this->nonNegativeInteger($tokens['per_reference_image'] ?? null)),
            'output_tokens' => $output,
            'reasoning_tokens' => $minReasoning + intdiv($maxReasoning - $minReasoning, 2),
        ], $rates);
    }

    /**
     * @param  array<string, mixed>  $generation
     * @return array{provider_cost_nanodollars: int, user_charge_nanodollars: int}
     */
    public function actual(array $generation): array
    {
        return match ($generation['status'] ?? null) {
            'failed' => $this->withMarkup(0),
            'succeeded' => $this->cost($generation['usage'] ?? [], $generation['rates'] ?? []),
            default => throw new InvalidArgumentException('Final cost requires a completed Pixelforge generation.'),
        };
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $rates
     * @return array{provider_cost_nanodollars: int, user_charge_nanodollars: int}
     */
    private function cost(array $usage, array $rates): array
    {
        $total = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $tokens = $this->nonNegativeInteger($usage[$type] ?? null);
            $rate = $this->nanodollarsPerToken($rates[$type] ?? null);
            $total = $this->add($total, $this->multiply($tokens, $rate));
        }

        return $this->withMarkup($total);
    }

    private function nanodollarsPerToken(mixed $rate): int
    {
        // One dollar per million tokens is 1,000 nanodollars per token.
        // Reject precision beyond this scale unless the extra digits are zeros.
        if (! is_string($rate) || ! preg_match('/\A([0-9]+)(?:\.([0-9]{1,3})0*)?\z/', $rate, $parts)) {
            throw new UnexpectedValueException('Pixelforge rate must be an exact decimal representable in nanodollars per token.');
        }

        $digits = ltrim($parts[1].str_pad($parts[2] ?? '', 3, '0'), '0');
        $value = filter_var($digits === '' ? '0' : $digits, FILTER_VALIDATE_INT);

        if ($value === false) {
            throw new OverflowException('Pixelforge rate exceeds integer range.');
        }

        return $value;
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new UnexpectedValueException('Expected a non-negative Pixelforge token count.');
        }

        return $value;
    }

    private function add(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new OverflowException('Pixelforge cost exceeds integer range.');
        }

        return $left + $right;
    }

    private function multiply(int $left, int $right): int
    {
        if ($right !== 0 && $left > intdiv(PHP_INT_MAX, $right)) {
            throw new OverflowException('Pixelforge calculation exceeds integer range.');
        }

        return $left * $right;
    }

    /** @return array{provider_cost_nanodollars: int, user_charge_nanodollars: int} */
    private function withMarkup(int $providerCost): array
    {
        // Round the 25% markup half up to the nearest nanodollar once per generation.
        $markup = intdiv($providerCost, 4) + (int) ($providerCost % 4 >= 2);

        return [
            'provider_cost_nanodollars' => $providerCost,
            'user_charge_nanodollars' => $this->add($providerCost, $markup),
        ];
    }
}
