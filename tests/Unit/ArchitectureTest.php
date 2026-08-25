<?php

use App\Domain\Achievements\Contracts\ProgressMetric;
use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorCode;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Contracts\WebhookHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/*
|--------------------------------------------------------------------------
| Architecture
|--------------------------------------------------------------------------
|
| The README describes a shared payments module, three bounded contexts and a
| dependency direction. These tests are what stop that description from drifting
| away from the code: every rule below is a claim the README makes.
|
*/

arch('the payments module never reaches into a domain')
    ->expect('App\Payments')
    ->not->toUse('App\Domain');

arch('the payments module never reaches into the http layer')
    ->expect('App\Payments')
    ->not->toUse('App\Http');

arch('a context never calls another context, only listens to it')
    ->expect('App\Domain\Ordering')
    ->not->toUse(['App\Domain\Achievements', 'App\Domain\Cashback']);

arch('achievements reads ordering history but never drives it')
    ->expect('App\Domain\Achievements')
    ->not->toUse([
        'App\Domain\Ordering\Actions',
        'App\Domain\Ordering\Listeners',
        'App\Domain\Cashback',
    ]);

arch('cashback is reached through the badge event, not through achievements code')
    ->expect('App\Domain\Cashback')
    ->not->toUse([
        'App\Domain\Achievements\Actions',
        'App\Domain\Achievements\Listeners',
        'App\Domain\Ordering\Actions',
        'App\Domain\Ordering\Listeners',
    ]);

arch('the domain does not know it is being served over http')
    ->expect('App\Domain')
    ->not->toUse(['App\Http', 'Illuminate\Http\Request', 'Illuminate\Http\JsonResponse']);

arch('money value objects cannot be mutated after construction')
    ->expect('App\Payments\ValueObjects')
    ->toBeFinal()
    ->toBeReadonly();

arch('contracts are interfaces')
    ->expect(['App\Payments\Contracts', 'App\Domain\Achievements\Contracts'])
    ->toBeInterfaces();

arch('statuses are enums')
    ->expect(['App\Payments\Enums', 'App\Domain\Ordering\Enums', 'App\Domain\Cashback\Enums'])
    ->toBeEnums();

arch('every gateway honours the payment contract')
    ->expect('App\Payments\Gateways')
    ->toImplement(PaymentGateway::class)
    ->toHaveSuffix('Gateway');

arch('every webhook handler honours the webhook contract')
    ->expect('App\Payments\Webhooks')
    ->toImplement(WebhookHandler::class)
    ->toHaveSuffix('WebhookHandler');

arch('adding an achievement group means adding a metric')
    ->expect('App\Domain\Achievements\Metrics')
    ->toImplement(ProgressMetric::class)
    ->toHaveSuffix('Metric');

arch('actions expose a single entry point')
    ->expect([
        'App\Domain\Ordering\Actions',
        'App\Domain\Achievements\Actions',
        'App\Domain\Cashback\Actions',
    ])
    ->toHaveMethod('handle');

arch('listeners are queued, so an external call never blocks a request')
    ->expect('App\Domain\Achievements\Listeners')
    ->toImplement(ShouldQueue::class)
    ->toHaveMethod('handle');

arch('cashback listeners are queued too')
    ->expect('App\Domain\Cashback\Listeners')
    ->toImplement(ShouldQueue::class)
    ->toHaveMethod('handle');

arch('models are eloquent models')
    ->expect([
        'App\Domain\Ordering\Models',
        'App\Domain\Achievements\Models',
        'App\Domain\Cashback\Models',
    ])
    ->toExtend(Model::class);

arch('controllers are thin http adapters')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller')
    ->toExtend(Controller::class)
    ->ignoring(Controller::class);

arch('every response shape is a resource, so no controller hand-rolls one again')
    ->expect('App\\Http\\Resources')
    ->toExtend(JsonResource::class)
    ->toHaveSuffix('Resource');

arch('every failure the api reports comes from the one error vocabulary')
    ->expect(ErrorCode::class)
    ->toBeEnum();

arch('the error vocabulary is the only place a status code is decided')
    ->expect('App\\Http\\Exceptions')
    ->toHaveSuffix('Renderer');

arch('no debugging left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();
