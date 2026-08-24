<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAchievement extends Model
{
    /** @use HasFactory<\Database\Factories\UserAchievementFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'achievement_key',
        'achievement_name',
        'group_key',
        'threshold',
        'unlocked_at',
    ];

    /**
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
