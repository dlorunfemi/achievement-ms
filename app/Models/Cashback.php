<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cashback extends Model
{
    /** @use HasFactory<\Database\Factories\CashbackFactory> */
    use HasFactory;

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
