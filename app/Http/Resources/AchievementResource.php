<?php

namespace App\Http\Resources;

use App\Domain\Achievements\Models\Achievement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Achievement
 */
class AchievementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'key' => $this->key,
            'name' => $this->name,
            'group_key' => $this->group_key,
            'threshold' => $this->threshold,
        ];
    }
}
