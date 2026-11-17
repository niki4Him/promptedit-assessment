<?php

namespace App\Http\Controllers;

use App\Http\Resources\GenerationResource;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationIndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('Generations', [
            'history' => function () use ($user): array {
                $generations = $user->generations()
                    ->latest()
                    ->orderByDesc('id')
                    ->simplePaginate(12, pageName: 'history');

                return [
                    'data' => GenerationResource::collection($generations->items())->resolve(),
                    'previous' => $generations->previousPageUrl(),
                    'next' => $generations->nextPageUrl(),
                ];
            },
        ]);
    }
}
