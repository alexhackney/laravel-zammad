<?php

declare(strict_types=1);

use AlexHackney\Zammad\Data\ObjectAttribute;
use AlexHackney\Zammad\Events\ZammadResponseLog;
use AlexHackney\Zammad\Requests\ObjectAttribute\AllObjectAttributesRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\CreateObjectAttributeRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\DestroyObjectAttributeRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\ExecuteMigrationsObjectAttributeRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\GetObjectAttributeRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\UpdateObjectAttributeRequest;
use AlexHackney\Zammad\Tests\Feature\Support\Mocks;
use AlexHackney\Zammad\Zammad;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

require_once __DIR__.'/Support/Mocks.php';

beforeEach(function () {
    config(['zammad.http_retry_delay' => 0]);
});

afterEach(function () {
    MockClient::destroyGlobal();
});

it('lists objects', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllObjectAttributesRequest::class => MockResponse::make([
            Mocks::objectAttribute(),
        ], 200),
    ]);

    $objects = (new Zammad)->object()->list();

    $this->assertInstanceOf(Collection::class, $objects);
    $this->assertTrue($objects->count() > 0);
    $this->assertInstanceOf(ObjectAttribute::class, $objects->first());
    $this->assertSame(10, $objects->first()->id);
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllObjectAttributesRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/object_manager_attributes'
        && $request->query()->all() === []);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('objects', 'list-objects');

it('shows a object', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllObjectAttributesRequest::class => MockResponse::make([
            Mocks::objectAttribute(['id' => 10]),
        ], 200),
        GetObjectAttributeRequest::class => MockResponse::make(
            Mocks::objectAttribute(['id' => 10]),
            200,
        ),
    ]);

    $objects = (new Zammad)->object()->list();
    Event::assertDispatched(ZammadResponseLog::class, 1);
    $object = $objects->last();

    $newObject = (new Zammad)->object()->show($object->id);

    $this->assertInstanceOf(ObjectAttribute::class, $newObject);
    $this->assertSame($object->id, $newObject->id);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetObjectAttributeRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/object_manager_attributes/10'
        && $request->query()->all() === []);
    Event::assertDispatched(ZammadResponseLog::class, 2);
})->group('objects', 'show-object');

it('creates a object', function () {
    $objectAttribute = ObjectAttribute::fakeCreateToArray();
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateObjectAttributeRequest::class => MockResponse::make(Mocks::objectAttribute([
            'id' => 10,
            'name' => $objectAttribute['name'],
            'display' => $objectAttribute['display'],
            'data_type' => $objectAttribute['data_type'],
            'data_option' => $objectAttribute['data_option'],
        ]), 201),
        DestroyObjectAttributeRequest::class => MockResponse::make([], 200),
        ExecuteMigrationsObjectAttributeRequest::class => MockResponse::make([], 200),
    ]);

    $object = (new Zammad)->object()->create($objectAttribute);

    $this->assertInstanceOf(ObjectAttribute::class, $object);
    $this->assertSame($objectAttribute['name'], $object->name);
    $this->assertSame($objectAttribute['display'], $object->display);
    Event::assertDispatched(ZammadResponseLog::class, 1);

    (new Zammad)->object()->delete($object->id);
    (new Zammad)->object()->executeMigrations();

    $mock->assertSent(fn (Request $request): bool => $request instanceof CreateObjectAttributeRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/object_manager_attributes'
        && $request->query()->all() === []
        && $request->body()->all() === $objectAttribute);
    $mock->assertSent(fn (Request $request): bool => $request instanceof DestroyObjectAttributeRequest
        && $request->getMethod() === Method::DELETE
        && $request->resolveEndpoint() === '/object_manager_attributes/10'
        && $request->query()->all() === []);
    $mock->assertSent(fn (Request $request): bool => $request instanceof ExecuteMigrationsObjectAttributeRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/object_manager_attributes_execute_migrations'
        && $request->query()->all() === []);
})->group('objects', 'create-object');

it('update a object', function () {
    $objectAttribute = ObjectAttribute::fakeCreateToArray();
    $updatedObjectAttribute = [
        'name' => $objectAttribute['name'],
        'object' => 'Ticket',
        'display' => 'Updated Sample Object',
        'position' => 1500,
        'data_type' => $objectAttribute['data_type'],
        'data_option' => [
            'options' => [
                'key-one' => 'First Key',
                'key-two' => 'Second Key',
                'key-three' => 'Third Key',
                'key-four' => 'Fourth Key',
            ],
            'default' => 'key-two',
        ],
    ];

    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateObjectAttributeRequest::class => MockResponse::make(Mocks::objectAttribute([
            'id' => 10,
            'name' => $objectAttribute['name'],
            'display' => $objectAttribute['display'],
            'position' => 1500,
            'data_type' => $objectAttribute['data_type'],
            'data_option' => $objectAttribute['data_option'],
        ]), 201),
        UpdateObjectAttributeRequest::class => MockResponse::make(Mocks::objectAttribute([
            'id' => 10,
            'name' => $updatedObjectAttribute['name'],
            'display' => $updatedObjectAttribute['display'],
            'position' => 1500,
            'data_type' => $updatedObjectAttribute['data_type'],
            'data_option' => $updatedObjectAttribute['data_option'],
        ]), 200),
        DestroyObjectAttributeRequest::class => MockResponse::make([], 200),
        ExecuteMigrationsObjectAttributeRequest::class => MockResponse::make([], 200),
    ]);

    $object = (new Zammad)->object()->create($objectAttribute);
    Event::assertDispatched(ZammadResponseLog::class, 1);
    $this->assertInstanceOf(ObjectAttribute::class, $object);

    expect($objectAttribute['display'])->toEqual($object->display);
    expect($updatedObjectAttribute['display'])->not()->toEqual($object->display);

    $object = (new Zammad)->object()->update($object->id, $updatedObjectAttribute);
    Event::assertDispatched(ZammadResponseLog::class, 2);
    expect($updatedObjectAttribute['display'])->toEqual($object->display);
    expect($objectAttribute['display'])->not()->toEqual($object->display);

    (new Zammad)->object()->delete($object->id);
    (new Zammad)->object()->executeMigrations();

    $mock->assertSent(fn (Request $request): bool => $request instanceof UpdateObjectAttributeRequest
        && $request->getMethod() === Method::PUT
        && $request->resolveEndpoint() === '/object_manager_attributes/10'
        && $request->query()->all() === []
        && $request->body()->all() === $updatedObjectAttribute);
})->group('objects', 'update-object');

it('deletes a object', function () {
    $objectAttribute = ObjectAttribute::fakeCreateToArray();
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateObjectAttributeRequest::class => MockResponse::make(Mocks::objectAttribute([
            'id' => 10,
            'name' => $objectAttribute['name'],
            'display' => $objectAttribute['display'],
        ]), 201),
        DestroyObjectAttributeRequest::class => MockResponse::make([], 200),
    ]);

    $object = (new Zammad)->object()->create($objectAttribute);
    Event::assertDispatched(ZammadResponseLog::class, 1);

    (new Zammad)->object()->delete($object->id);
    Event::assertDispatched(ZammadResponseLog::class, 2);

    $mock->assertSent(fn (Request $request): bool => $request instanceof DestroyObjectAttributeRequest
        && $request->getMethod() === Method::DELETE
        && $request->resolveEndpoint() === '/object_manager_attributes/10'
        && $request->query()->all() === []);
})->group('objects');

it('execute database migrations', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        ExecuteMigrationsObjectAttributeRequest::class => MockResponse::make([], 200),
    ]);

    (new Zammad)->object()->executeMigrations();

    $mock->assertSent(fn (Request $request): bool => $request instanceof ExecuteMigrationsObjectAttributeRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/object_manager_attributes_execute_migrations'
        && $request->query()->all() === []);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('objects');

it('throws when listing objects fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllObjectAttributesRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->object()->list())
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllObjectAttributesRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/object_manager_attributes'
        && $request->query()->all() === []);
})->group('objects');

it('throws when showing an object fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetObjectAttributeRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->object()->show(10))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetObjectAttributeRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/object_manager_attributes/10'
        && $request->query()->all() === []);
})->group('objects');

it('throws when creating an object fails', function () {
    $payload = ObjectAttribute::fakeCreateToArray();
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateObjectAttributeRequest::class => MockResponse::make([], 422),
    ]);

    expect(fn () => (new Zammad)->object()->create($payload))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof CreateObjectAttributeRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/object_manager_attributes'
        && $request->query()->all() === []
        && $request->body()->all() === $payload);
})->group('objects');

it('throws when updating an object fails', function () {
    $payload = ['display' => 'Updated'];
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        UpdateObjectAttributeRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->object()->update(10, $payload))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof UpdateObjectAttributeRequest
        && $request->getMethod() === Method::PUT
        && $request->resolveEndpoint() === '/object_manager_attributes/10'
        && $request->query()->all() === []
        && $request->body()->all() === $payload);
})->group('objects');

it('throws when deleting an object fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        DestroyObjectAttributeRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->object()->delete(10))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof DestroyObjectAttributeRequest
        && $request->getMethod() === Method::DELETE
        && $request->resolveEndpoint() === '/object_manager_attributes/10'
        && $request->query()->all() === []);
})->group('objects');

it('throws when executing object migrations fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        ExecuteMigrationsObjectAttributeRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->object()->executeMigrations())
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof ExecuteMigrationsObjectAttributeRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/object_manager_attributes_execute_migrations'
        && $request->query()->all() === []);
})->group('objects');
