<?php

namespace App\Http\Controllers;

use App\Actions\RecoverGeneration;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecoverGenerationController extends Controller
{
    public function __invoke(Request $request, string $generation, RecoverGeneration $recover): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $recover->handle($user->generations()->findOrFail($generation));

        return back(303);
    }
}
