<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Tests\Feature;

use AlexHackney\Zammad\Data\User;
use AlexHackney\Zammad\Events\ZammadResponseLog;
use AlexHackney\Zammad\Requests\Users\AllUsersRequest;
use AlexHackney\Zammad\Requests\Users\CreateUserRequest;
use AlexHackney\Zammad\Requests\Users\DestroyUserRequest;
use AlexHackney\Zammad\Requests\Users\GetUserRequest;
use AlexHackney\Zammad\Requests\Users\SearchUserRequest;
use AlexHackney\Zammad\Requests\Users\UpdateUserRequest;
use AlexHackney\Zammad\Tests\Feature\Support\Mocks;
use AlexHackney\Zammad\Zammad;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
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

it('show current user', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetUserRequest::class => MockResponse::make(Mocks::user(), 200),
    ]);

    $user = (new Zammad)->user()->me();

    $this->assertInstanceOf(User::class, $user);
    $this->assertSame(5, $user->id);
    $this->assertSame('max.mustermann@example.test', $user->email);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/me'
        && $request->query()->all() === ['expand' => false]);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('users');

it('list users', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllUsersRequest::class => MockResponse::make([Mocks::user()], 200),
    ]);

    $users = (new Zammad)->user()->list();

    $this->assertInstanceOf(Collection::class, $users);
    $this->assertCount(1, $users);
    $users->each(function (User $user) {
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(5, $user->id);
    });

    $mock->assertSent(fn (Request $request): bool => $request instanceof AllUsersRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users'
        && $request->query()->all() === []);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('users', 'list-users');

it('searches a user', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllUsersRequest::class => MockResponse::make([Mocks::user()], 200),
        SearchUserRequest::class => MockResponse::make([Mocks::user()], 200),
    ]);

    $users = (new Zammad)->user()->paginate(1, 100)->list();
    Event::assertDispatched(ZammadResponseLog::class, 1);
    $user = $users->last();

    $searchedUser = (new Zammad)->user()->search($user->email);

    $this->assertInstanceOf(User::class, $searchedUser);
    $this->assertSame($user->id, $searchedUser->id);
    $this->assertSame($user->email, $searchedUser->email);
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllUsersRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users'
        && $request->query()->all() === ['per_page' => 100, 'page' => 1]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof SearchUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/search'
        && $request->query()->all() === ['query' => 'max.mustermann@example.test', 'limit' => 1]);
    Event::assertDispatched(ZammadResponseLog::class, 2);
})->group('users');

it('searches a non existing user', function () {
    $term = 'email:does@not.exist';
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([], 200),
    ]);

    $user = (new Zammad)->user()->search($term);

    $this->assertNull($user);
    $mock->assertSent(fn (Request $request): bool => $request instanceof SearchUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/search'
        && $request->query()->all() === ['query' => 'email:does@not.exist', 'limit' => 1]);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('users');

it('shows a user', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllUsersRequest::class => MockResponse::make([Mocks::user()], 200),
        GetUserRequest::class => MockResponse::make(Mocks::user(), 200),
    ]);

    $users = (new Zammad)->user()->list();
    Event::assertDispatched(ZammadResponseLog::class, 1);
    $user = $users->last();

    $newUser = (new Zammad)->user()->show($user->id);

    $this->assertInstanceOf(User::class, $newUser);
    $this->assertSame($user->id, $newUser->id);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/5'
        && $request->query()->all() === ['expand' => false]);
    Event::assertDispatched(ZammadResponseLog::class, 2);
})->group('users');

it('creates a user', function () {
    $firstname = Str::orderedUuid()->toString();
    $lastname = Str::orderedUuid()->toString();
    $email = $firstname.'@example.test';

    $data = [
        'firstname' => $firstname,
        'lastname' => $lastname,
        'email' => $email,
    ];

    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateUserRequest::class => MockResponse::make(Mocks::user([
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'login' => $email,
        ]), 201),
    ]);

    $user = (new Zammad)->user()->create($data);

    $this->assertInstanceOf(User::class, $user);
    $this->assertSame($firstname, $user->first_name);
    $this->assertSame($lastname, $user->last_name);
    $this->assertStringEndsWith($email, $user->email);
    $mock->assertSent(fn (Request $request): bool => $request instanceof CreateUserRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/users'
        && $request->query()->all() === ['expand' => false]
        && $request->body()->all() === $data);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('users');

it('updates a user', function () {
    $firstname = Str::orderedUuid()->toString();
    $lastname = Str::orderedUuid()->toString();

    $data = [
        'firstname' => $firstname,
        'lastname' => $lastname,
    ];

    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllUsersRequest::class => MockResponse::make([Mocks::user()], 200),
        UpdateUserRequest::class => MockResponse::make(Mocks::user([
            'firstname' => $firstname,
            'lastname' => $lastname,
        ]), 200),
    ]);

    $users = (new Zammad)->user()->list();
    Event::assertDispatched(ZammadResponseLog::class, 1);
    $user = $users->last();

    $updatedUser = (new Zammad)->user()->update($user->id, $data);
    Event::assertDispatched(ZammadResponseLog::class, 2);

    expect($firstname)->toEqual($updatedUser->first_name);
    expect($lastname)->toEqual($updatedUser->last_name);
    $mock->assertSent(fn (Request $request): bool => $request instanceof UpdateUserRequest
        && $request->getMethod() === Method::PUT
        && $request->resolveEndpoint() === '/users/5'
        && $request->query()->all() === ['expand' => false]
        && $request->body()->all() === $data);
})->group('users');

it('searches or creates a user', function () {
    $email = Str::orderedUuid()->toString().'@example.test';
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([], 200),
        CreateUserRequest::class => MockResponse::make(Mocks::user([
            'email' => $email,
            'login' => $email,
        ]), 201),
    ]);

    $user = (new Zammad)->user()->searchOrCreateByEmail($email);

    $this->assertSame($email, $user->email);
    $mock->assertSent(fn (Request $request): bool => $request instanceof SearchUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/search'
        && $request->query()->all() === ['query' => 'email:'.$email, 'limit' => 1]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof CreateUserRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/users'
        && $request->query()->all() === ['expand' => false]
        && $request->body()->all() === ['email' => $email]);
    Event::assertDispatched(ZammadResponseLog::class, 2);
})->group('users');

it('deletes a user', function () {
    $email = Str::orderedUuid()->toString().'@example.test';
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([], 200),
        CreateUserRequest::class => MockResponse::make(Mocks::user([
            'id' => 5,
            'email' => $email,
            'login' => $email,
        ]), 201),
        DestroyUserRequest::class => MockResponse::make([], 200),
    ]);

    $user = (new Zammad)->user()->searchOrCreateByEmail($email);
    $this->assertSame($email, $user->email);
    Event::assertDispatched(ZammadResponseLog::class, 2);
    (new Zammad)->user()->delete($user->id);
    Event::assertDispatched(ZammadResponseLog::class, 3);

    $mock->assertSent(fn (Request $request): bool => $request instanceof DestroyUserRequest
        && $request->getMethod() === Method::DELETE
        && $request->resolveEndpoint() === '/users/5'
        && $request->query()->all() === []);
})->group('users');

it('show current user expanded', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetUserRequest::class => MockResponse::make(Mocks::user(), 200),
    ]);

    $user = (new Zammad)->user()->me();
    $userExpand = (new Zammad)->user()->expand()->me();
    $this->assertInstanceOf(User::class, $user);
    $this->assertInstanceOf(User::class, $userExpand);
    Event::assertDispatched(ZammadResponseLog::class, 2);

    $this->assertSame($user->id, $userExpand->id);
    $this->assertNull($user->expanded);
    $this->assertNotNull($userExpand->expanded);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/me'
        && $request->query()->all() === ['expand' => false]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/me'
        && $request->query()->all() === ['expand' => true]);
})->group('users', 'expand');

it('show user expanded', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllUsersRequest::class => MockResponse::make([Mocks::user()], 200),
        GetUserRequest::class => MockResponse::make(Mocks::user(), 200),
    ]);

    $users = (new Zammad)->user()->list();
    Event::assertDispatched(ZammadResponseLog::class, 1);
    $usr = $users->last();

    $user = (new Zammad)->user()->show($usr->id);
    $userExpand = (new Zammad)->user()->expand()->show($usr->id);
    $this->assertInstanceOf(User::class, $user);
    $this->assertInstanceOf(User::class, $userExpand);
    Event::assertDispatched(ZammadResponseLog::class, 3);

    $this->assertSame($user->id, $userExpand->id);
    $this->assertNull($user->expanded);
    $this->assertNotNull($userExpand->expanded);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/5'
        && $request->query()->all() === ['expand' => false]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/5'
        && $request->query()->all() === ['expand' => true]);
})->group('users', 'expand');

it('paginates user list', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllUsersRequest::class => MockResponse::make([Mocks::user()], 200),
    ]);

    $users = (new Zammad)->user()->paginate(1, 2)->list();
    $usersTwo = (new Zammad)->user()->paginate(2, 2)->list();

    $this->assertNotSame($users, $usersTwo);
    $this->assertInstanceOf(User::class, $users->first());
    $this->assertInstanceOf(User::class, $usersTwo->first());
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllUsersRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users'
        && $request->query()->all() === ['per_page' => 2, 'page' => 1]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllUsersRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users'
        && $request->query()->all() === ['per_page' => 2, 'page' => 2]);
})->group('users', 'paginate');

it('paginates user list with page and perPage methods', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllUsersRequest::class => MockResponse::make([Mocks::user()], 200),
    ]);

    $users = (new Zammad)->user()->page(1)->perPage(2)->list();
    $usersTwo = (new Zammad)->user()->page(2)->perPage(2)->list();

    $this->assertNotSame($users, $usersTwo);
    $this->assertInstanceOf(User::class, $users->first());
    $this->assertInstanceOf(User::class, $usersTwo->first());
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllUsersRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users'
        && $request->query()->all() === ['per_page' => 2, 'page' => 1]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllUsersRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users'
        && $request->query()->all() === ['per_page' => 2, 'page' => 2]);
})->group('users', 'paginate');

it('limits user list with limit method', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([Mocks::user()], 200),
    ]);

    $users = (new Zammad)->user()->limit()->search('info');

    $this->assertInstanceOf(User::class, $users);

    $users = (new Zammad)->user()->limit(10)->search('info');

    $this->assertInstanceOf(Collection::class, $users);
    expect(count($users))->toBeLessThanOrEqual(10);
    $mock->assertSent(fn (Request $request): bool => $request instanceof SearchUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/search'
        && $request->query()->all() === ['query' => 'info', 'limit' => 1]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof SearchUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/search'
        && $request->query()->all() === ['query' => 'info', 'limit' => 10]);
})->group('users', 'limit');

it('throws when showing current user fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetUserRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->user()->me())
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/me'
        && $request->query()->all() === ['expand' => false]);
})->group('users');

it('throws when listing users fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllUsersRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->user()->list())
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllUsersRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users'
        && $request->query()->all() === []);
})->group('users');

it('throws when searching a user fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->user()->search('info'))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof SearchUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/search'
        && $request->query()->all() === ['query' => 'info', 'limit' => 1]);
})->group('users');

it('throws when showing a user fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetUserRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->user()->show(5))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/5'
        && $request->query()->all() === ['expand' => false]);
})->group('users');

it('throws when creating a user fails', function () {
    $payload = ['email' => 'max@example.test'];
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateUserRequest::class => MockResponse::make([], 422),
    ]);

    expect(fn () => (new Zammad)->user()->create($payload))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof CreateUserRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/users'
        && $request->query()->all() === ['expand' => false]
        && $request->body()->all() === $payload);
})->group('users');

it('throws when updating a user fails', function () {
    $payload = ['firstname' => 'Max'];
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        UpdateUserRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->user()->update(5, $payload))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof UpdateUserRequest
        && $request->getMethod() === Method::PUT
        && $request->resolveEndpoint() === '/users/5'
        && $request->query()->all() === ['expand' => false]
        && $request->body()->all() === $payload);
})->group('users');

it('throws when deleting a user fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        DestroyUserRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->user()->delete(5))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof DestroyUserRequest
        && $request->getMethod() === Method::DELETE
        && $request->resolveEndpoint() === '/users/5'
        && $request->query()->all() === []);
})->group('users');

it('throws when searching or creating a user fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->user()->searchOrCreateByEmail('max@example.test'))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof SearchUserRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/users/search'
        && $request->query()->all() === ['query' => 'email:max@example.test', 'limit' => 1]);
})->group('users');
