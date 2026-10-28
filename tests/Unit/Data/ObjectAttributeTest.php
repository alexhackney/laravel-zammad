<?php

declare(strict_types=1);

use AlexHackney\Zammad\Data\ObjectAttribute;
use Saloon\Http\Faking\MockClient;

afterEach(function () {
    MockClient::destroyGlobal();
});

it('accepts a string name for fake object attributes', function () {
    expect(ObjectAttribute::fake(name: 'customer_reference')->name)->toBe('customer_reference');
});

it('declares an array return type for fake create payloads', function () {
    $method = new ReflectionMethod(ObjectAttribute::class, 'fakeCreateToArray');

    expect((string) $method->getReturnType())->toBe('array')
        ->and(ObjectAttribute::fakeCreateToArray())->toBeArray();
});
