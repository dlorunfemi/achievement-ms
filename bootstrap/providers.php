<?php

use App\Providers\AppServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\PaymentServiceProvider;

return [
    AppServiceProvider::class,
    PaymentServiceProvider::class,
    DomainServiceProvider::class,
];
