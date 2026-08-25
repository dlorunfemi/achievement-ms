<?php

namespace App\Domain\Achievements\Models;

use Database\Factories\AchievementFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A catalog definition. Achievements are grouped (e.g. "purchases") and ordered
 * within a group by the threshold the user's progress must reach to unlock them.
 */
#[UseFactory(AchievementFactory::class)]
class Achievement extends Model
{
    /** @use HasFactory<AchievementFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'group_key',
        'threshold',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
        ];
    }

    /**
     * Catalog order: by group, then by ascending difficulty.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function inProgressionOrder(Builder $query): Builder
    {
        return $query->orderBy('group_key')->orderBy('threshold');
    }
}
