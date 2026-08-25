<?php

namespace App\Domain\Cashback\Models;

use App\Models\User;
use App\Payments\ValueObjects\RecipientAccount;
use Database\Factories\PayoutAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank account a user can be paid into.
 *
 * Owned by the Cashback context, which is what puts money in it. The shared Payments
 * module knows only the RecipientAccount value object this maps to, so the dependency
 * runs domain -> infrastructure and never back.
 */
#[UseFactory(PayoutAccountFactory::class)]
class PayoutAccount extends Model
{
    /** @use HasFactory<PayoutAccountFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'bank_code',
        'bank_name',
        'account_number',
        'account_name',
        'currency',
        'is_default',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'NGN',
        'is_default' => false,
        'recipient_tokens' => '{}',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'recipient_tokens' => 'array',
        ];
    }

    /**
     * Preferred accounts first, then most recently added, so the first row of this
     * ordering is always the account to pay.
     */
    #[Scope]
    protected function preferred(Builder $query): Builder
    {
        return $query->orderByDesc('is_default')->orderByDesc('id');
    }

    /**
     * The token a provider issued for this account, if one has been registered.
     *
     * Deliberately absent from $fillable: it is minted by a provider, never sent in
     * by a caller.
     */
    public function recipientTokenFor(string $gateway): ?string
    {
        $token = ($this->recipient_tokens ?? [])[$gateway] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Keep a provider's recipient token so the next payout to this account does not
     * have to register it again.
     */
    public function rememberRecipientToken(string $gateway, string $token): void
    {
        $this->forceFill([
            'recipient_tokens' => [...($this->recipient_tokens ?? []), $gateway => $token],
        ])->save();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Describe this account in the shape the payments module hands to a provider.
     *
     * Bank codes are provider-specific (a NIP code for Paystack and Monnify, a bank
     * code for Flutterwave), so the stored value is passed through unchanged.
     */
    public function toRecipientAccount(): RecipientAccount
    {
        return new RecipientAccount(
            accountNumber: $this->account_number,
            bankCode: $this->bank_code,
            accountName: $this->account_name,
            currency: $this->currency,
        );
    }
}
