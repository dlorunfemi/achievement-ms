<?php

namespace App\Http\Controllers;

use App\Domain\Achievements\Actions\BuildUserProgression;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserAchievementsController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * Returns the payload the assessment specifies, with those exact top-level keys.
     * The response is a flat projection of a value object rather than a model, so it
     * is returned directly instead of through an Eloquent resource, which would wrap
     * it in a "data" envelope and break the contract.
     */
    public function __invoke(User $user, BuildUserProgression $buildProgression): JsonResponse
    {
        return response()->json(
            $buildProgression->handle($user)->toArray()
        );
    }
}
