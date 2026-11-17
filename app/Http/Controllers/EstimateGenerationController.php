<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Services\Pixelforge\PixelforgePricing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use OverflowException;
use UnexpectedValueException;

class EstimateGenerationController extends Controller
{
    public function __invoke(StoreGenerationRequest $request, PixelforgePricing $pricing): JsonResponse
    {
        try {
            $estimate = $pricing->estimate(
                $request->validated('prompt'),
                $request->validated('size'),
                $request->validated('quality'),
                count($request->validated('reference_images', [])),
                useCachedPricing: true,
            );
        } catch (ConnectionException|RequestException|InvalidArgumentException|OverflowException|UnexpectedValueException) {
            return response()->json(['message' => 'Image pricing is temporarily unavailable.'], 503);
        }

        return response()->json([
            'estimated_user_charge_nanodollars' => (string) $estimate['user_charge_nanodollars'],
        ]);
    }
}
