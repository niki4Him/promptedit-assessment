<?php

namespace App\Http\Controllers;

use App\Actions\SubmitGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class StoreGenerationController extends Controller
{
    public function __invoke(StoreGenerationRequest $request, SubmitGeneration $submit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $generation = $submit->handle(
            $user,
            $request->validated('prompt'),
            $request->validated('size'),
            $request->validated('quality'),
            $request->validated('reference_images'),
        );

        Inertia::flash('generation_id', $generation->getKey());

        return back(303);
    }
}
