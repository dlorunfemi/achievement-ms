<?php

namespace Database\Factories;

use App\Domain\Achievements\Models\UserBadge;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Models\Cashback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cashback>
 */
class CashbackFactory extends Factory
{
    protected $model = Cashback::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $userBadge = UserBadge::factory();

        return [
            'user_badge_id' => $userBadge,
            'user_id' => fn (array $attributes): int => UserBadge::findOrFail((int) $attributes['user_badge_id'])->user_id,
            'amount_minor' => 30_000,
            'currency' => 'NGN',
            'status' => PayoutStatus::Pending,
            'gateway' => 'fake',
            'idempotency_key' => fn (array $attributes): string => 'cashback:user-badge:'.$attributes['user_badge_id'],
            'attempts' => 0,
        ];
    }

    /**
     * Accepted by the provider and awaiting settlement — the state a payout sits in
     * until a callback arrives, or the reconciliation sweep goes looking.
     */
    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => PayoutStatus::Processing,
            'gateway_reference' => 'ref_'.fake()->unique()->uuid(),
            'attempts' => 1,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PayoutStatus::Paid,
            'gateway_reference' => 'ref_'.fake()->unique()->uuid(),
            'attempts' => 1,
            'paid_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Insufficient balance'): static
    {
        return $this->state(fn (): array => [
            'status' => PayoutStatus::Failed,
            'failure_reason' => $reason,
            'attempts' => 1,
        ]);
    }
}
