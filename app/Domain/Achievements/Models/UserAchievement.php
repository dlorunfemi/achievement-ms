<?php

namespace App\Domain\Achievements\Models;

use App\Models\User;
use Database\Factories\UserAchievementFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An achievement a user has unlocked. The catalog columns are snapshotted at unlock
 * time so later edits to the Achievement definition never rewrite history.
 *
 * @property Carbon|null $unlocked_at
 * @property-read User $user
 */
#[UseFactory(UserAchievementFactory::class)]
class UserAchievement extends Model
{
    /** @use HasFactory<UserAchievementFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'achievement_key',
        'achievement_name',
        'group_key',
        'threshold',
        'unlocked_at',
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
            'unlocked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
