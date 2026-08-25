<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Achievements\Jobs\BackfillAchievementProgress;
use App\Domain\Achievements\Models\Badge;
use App\Http\Controllers\Controller;
use App\Http\Resources\BadgeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BadgeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'badges' => BadgeResource::collection(
                Badge::query()->inProgressionOrder()->get()
            ),
        ]);
    }

    /**
     * Add a badge.
     *
     * Badges need no counterpart in code the way achievements do — the threshold is
     * counted against the user's total unlocked achievements, whatever group they
     * came from — so anything the schema accepts is legitimate here.
     */
    public function store(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'key' => ['required', 'string', 'max:255', Rule::unique('badges', 'key')],
            'name' => ['required', 'string', 'max:255'],
            'threshold' => ['required', 'integer', 'min:1', Rule::unique('badges', 'threshold')],
        ], [
            'threshold.min' => 'A badge at zero achievements would pay every new user ₦300 for doing nothing.',
        ]);

        $badge = Badge::query()->create($attributes);

        BackfillAchievementProgress::dispatch();

        return response()->json([
            'badge' => new BadgeResource($badge),
            'backfill' => 'Queued. Users who already qualify will unlock it and be paid the badge cashback.',
        ], 201);
    }

    public function destroy(Badge $badge): JsonResponse
    {
        $badge->delete();

        return response()->json([
            'message' => 'Removed from the catalog. Badges users already hold, and cashback already paid, are unaffected.',
        ]);
    }
}
