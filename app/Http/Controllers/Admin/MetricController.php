<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Achievements\Actions\ListScorableGroups;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MetricController extends Controller
{
    /**
     * The group keys an achievement may be created against.
     *
     * Exists so the constraint behind admin/achievements is discoverable rather than
     * only discoverable by tripping over a validation error.
     */
    public function __invoke(ListScorableGroups $scorableGroups): JsonResponse
    {
        return response()->json([
            'scorable_groups' => $scorableGroups->handle(),
            'note' => 'A new group needs a ProgressMetric class registered in DomainServiceProvider::PROGRESS_METRICS.',
        ]);
    }
}
