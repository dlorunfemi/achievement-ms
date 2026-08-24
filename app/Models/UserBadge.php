<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserBadge extends Model
{
    /** @use HasFactory<\Database\Factories\UserBadgeFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'badge_key',
        'badge_name',
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

    /**
     * @return HasOne<Cashback, $this>
     */
    public function cashbackPayout(): HasOne
    {
        return $this->hasOne(Cashback::class);
    }
}
