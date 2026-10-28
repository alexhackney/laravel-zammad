<?php

declare(strict_types=1);

use AlexHackney\Zammad\Requests\Users\AllUsersRequest;
use AlexHackney\Zammad\ZammadConnector;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('normalizes a Stringable token independently of the URL', function () {
    config(['zammad.token' => Str::of('stringable-token')]);

    expect((new ZammadConnector)->setAuth())->toBe('stringable-token');
});

it('normalizes Stringable connector configuration', function (string $kind) {
    $make = fn (string $value) => $kind === 'Illuminate' ? Str::of($value) : new class($value) implements Stringable
    {
        public function __construct(private string $value) {}

        public function __toString(): string
        {
            return $this->value;
        }
    };
    config([
        'zammad.url' => $make('https://zammad.test///'),
        'zammad.token' => $make('stringable-token'),
    ]);
    $connector = new ZammadConnector;

    expect($connector->resolveBaseUrl())->toBe('https://zammad.test/api/v1')
        ->and($connector->setAuth())->toBe('stringable-token')
        ->and($connector->createPendingRequest(new AllUsersRequest, new MockClient([MockResponse::make([])]))->headers()->get('Authorization'))->toBe('Bearer stringable-token');
})->with(['Illuminate', 'custom']);
