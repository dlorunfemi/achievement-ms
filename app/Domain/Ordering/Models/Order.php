<?php

namespace App\Domain\Ordering\Models;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Models\User;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single purchase. Only a completed order counts toward a user's progression.
 *
 * @property OrderStatus $status
 * @property Carbon|null $placed_at
 * @property-read User $user
 */
#[UseFactory(OrderFactory::class)]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        'amount_minor',
        'currency',
        'status',
        'placed_at',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'NGN',
        'status' => OrderStatus::Pending->value,
        'quantity' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount_minor' => 'integer',
            'status' => OrderStatus::class,
            'placed_at' => 'datetime',
        ];
    }

    /**
     * Orders that count toward a user's purchase achievements.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function completed(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Completed);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
