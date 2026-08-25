<?php

use App\Payments\ValueObjects\RecipientAccount;

it('is immutable', function () {
    expect(new ReflectionClass(RecipientAccount::class))->isReadOnly()->toBeTrue();
});
