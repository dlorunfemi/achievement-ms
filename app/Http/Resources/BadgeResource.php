<?php

namespace App\Http\Resources;

use App\Domain\Achievements\Models\Badge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Badge
 */
class BadgeResource extends JsonResource
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
            'threshold' => $this->threshold,
        ];
    }
}
