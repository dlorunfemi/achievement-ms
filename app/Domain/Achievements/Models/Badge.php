<?php

namespace App\Domain\Achievements\Models;

use Database\Factories\BadgeFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A catalog definition. A badge is earned once the user's total number of unlocked
 * achievements reaches its threshold.
 */
#[UseFactory(BadgeFactory::class)]
class Badge extends Model
{
    /** @use HasFactory<BadgeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
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
     * Catalog order: easiest badge first.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function inProgressionOrder(Builder $query): Builder
    {
        return $query->orderBy('threshold');
    }
}
