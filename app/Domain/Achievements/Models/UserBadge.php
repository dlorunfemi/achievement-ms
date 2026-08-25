<?php

namespace App\Domain\Achievements\Models;

use App\Domain\Cashback\Models\Cashback;
use App\Models\User;
use Database\Factories\UserBadgeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A badge a user has earned. Unique per user and badge, which is what makes the
 * ₦300 cashback exactly-once.
 */
#[UseFactory(UserBadgeFactory::class)]
class UserBadge extends Model
{
    /** @use HasFactory<UserBadgeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'badge_key',
        'badge_name',
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

    /**
     * @return HasOne<Cashback, $this>
     */
    public function cashbackPayout(): HasOne
    {
        return $this->hasOne(Cashback::class);
    }
}
