<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Achievements\Actions\ListScorableGroups;
use App\Domain\Achievements\Jobs\BackfillAchievementProgress;
use App\Domain\Achievements\Models\Achievement;
use App\Http\Controllers\Controller;
use App\Http\Resources\AchievementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AchievementController extends Controller
{
    /**
     * The achievement catalog, grouped and ordered the way progression reads it.
     */
    public function index(ListScorableGroups $scorableGroups): JsonResponse
    {
        return response()->json([
            'scorable_groups' => $scorableGroups->handle(),
            'achievements' => AchievementResource::collection(
                Achievement::query()->inProgressionOrder()->get()
            ),
        ]);
    }

    /**
     * Add a rung to a progression.
     *
     * A group_key with no ProgressMetric behind it is refused rather than stored:
     * nothing would ever measure it, so the row would sit in the catalog looking
     * legitimate while being permanently unreachable. Adding a whole new progression
     * is a code change — one metric class, registered in DomainServiceProvider.
     */
    public function store(Request $request, ListScorableGroups $scorableGroups): JsonResponse
    {
        $attributes = $request->validate([
            'key' => ['required', 'string', 'max:255', Rule::unique('achievements', 'key')],
            'name' => ['required', 'string', 'max:255'],
            'group_key' => ['required', 'string', Rule::in($scorableGroups->handle())],

            // The unique (group_key, threshold) index says this too, but a 422 is a
            // better answer to "two achievements at 5 purchases" than a 500.
            'threshold' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('achievements', 'threshold')
                    ->where('group_key', $request->string('group_key')->toString()),
            ],
        ], [
            'group_key.in' => 'No progress metric scores that group. Add a ProgressMetric class and register it in DomainServiceProvider first; GET admin/metrics lists the groups that exist.',
            'threshold.unique' => 'That group already has an achievement at this threshold.',
        ]);

        $achievement = Achievement::query()->create($attributes);

        BackfillAchievementProgress::dispatch();

        return response()->json([
            'achievement' => new AchievementResource($achievement),
            'backfill' => 'Queued. Users who already qualify will unlock it, which can unlock badges and pay cashback.',
        ], 201);
    }

    /**
     * Remove a rung.
     *
     * Already-unlocked achievements survive: user_achievements snapshots the name,
     * group and threshold at unlock time and holds no foreign key, so a user keeps
     * what they earned and their badge count does not move.
     */
    public function destroy(Achievement $achievement): JsonResponse
    {
        $achievement->delete();

        return response()->json([
            'message' => 'Removed from the catalog. Achievements users already hold are unaffected.',
        ]);
    }
}
