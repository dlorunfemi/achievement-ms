<?php

namespace Database\Factories;

use App\Domain\Cashback\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutAccount>
 */
class PayoutAccountFactory extends Factory
{
    protected $model = PayoutAccount::class;

    /**
     * @var list<array{code: string, name: string}>
     */
    private const BANKS = [
        ['code' => '058', 'name' => 'Guaranty Trust Bank'],
        ['code' => '044', 'name' => 'Access Bank'],
        ['code' => '057', 'name' => 'Zenith Bank'],
        ['code' => '033', 'name' => 'United Bank for Africa'],
        ['code' => '011', 'name' => 'First Bank of Nigeria'],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bank = fake()->randomElement(self::BANKS);

        return [
            'user_id' => User::factory(),
            'bank_code' => $bank['code'],
            'bank_name' => $bank['name'],
            'account_number' => fake()->numerify('##########'),
            'account_name' => fake()->name(),
            'currency' => 'NGN',
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    public function forBank(string $code, string $name): static
    {
        return $this->state(fn (): array => ['bank_code' => $code, 'bank_name' => $name]);
    }
}
