<?php

namespace App\Domain\Cashback\Models;

use App\Domain\Achievements\Models\UserBadge;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Models\User;
use Database\Factories\CashbackFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The ₦300 reward owed for an unlocked badge.
 *
 * The row is created before the gateway is called and carries an idempotency key
 * derived from the user badge, so a retried job or a replayed BadgeUnlocked event
 * resolves to the same record and can never pay the user twice.
 */
#[UseFactory(CashbackFactory::class)]
class Cashback extends Model
{
    /** @use HasFactory<CashbackFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_badge_id',
        'user_id',
        'amount_minor',
        'currency',
        'status',
        'gateway',
        'gateway_reference',
        'idempotency_key',
        'failure_reason',
        'attempts',
        'paid_at',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'NGN',
        'status' => PayoutStatus::Pending->value,
        'attempts' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'attempts' => 'integer',
            'status' => PayoutStatus::class,
            'paid_at' => 'datetime',
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
     * @return BelongsTo<UserBadge, $this>
     */
    public function userBadge(): BelongsTo
    {
        return $this->belongsTo(UserBadge::class);
    }
}
