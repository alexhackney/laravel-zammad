<?php

declare(strict_types=1);

use AlexHackney\Zammad\Data\User;
use AlexHackney\Zammad\Requests\Users\CreateUserRequest;
use AlexHackney\Zammad\Requests\Users\GetUserRequest;
use AlexHackney\Zammad\Requests\Users\SearchUserRequest;
use AlexHackney\Zammad\Requests\Users\UpdateUserRequest;
use AlexHackney\Zammad\Zammad;
use Illuminate\Support\Collection;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// derived from DTO fields, not a captured Zammad response
function userPayload(array $overrides = []): array
{
    return array_merge([
        'id' => 5,
        'firstname' => 'Max',
        'lastname' => 'Mustermann',
        'login' => 'max',
        'email' => 'max@example.com',
        'last_login' => '2024-01-01T10:00:00.000Z',
        'updated_at' => '2024-01-02T10:00:00.000Z',
        'created_at' => '2024-01-01T09:00:00.000Z',
    ], $overrides);
}

beforeEach(function () {
    config()->set('zammad.http_retry_delay', 0);
});

afterEach(function () {
    MockClient::destroyGlobal();
});

it('requests only one search hit for the single user return mode', function (?int $limit): void {
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([userPayload(), userPayload(['id' => 6])]),
    ]);
    $resource = (new Zammad)->user();
    if ($limit !== null) {
        $resource->limit($limit);
    }

    $user = $resource->search('max');

    expect($user)->toBeInstanceOf(User::class)->and($user->id)->toBe(5);
    $mock->assertSent(fn ($request): bool => $request instanceof SearchUserRequest
        && $request->query()->all() === ['query' => 'max', 'limit' => 1]);
})->with([null, -1, 0, 1]);

it('returns null when a default user search has no hits', function (): void {
    $mock = MockClient::global([SearchUserRequest::class => MockResponse::make([])]);

    expect((new Zammad)->user()->search('missing'))->toBeNull();
    $mock->assertSent(fn ($request): bool => $request instanceof SearchUserRequest
        && $request->query()->all() === ['query' => 'missing', 'limit' => 1]);
});

it('preserves an explicit larger search limit and returns a collection', function (): void {
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([userPayload(), userPayload(['id' => 6])]),
    ]);

    $users = (new Zammad)->user()->limit(10)->search('max');

    expect($users)->toBeInstanceOf(Collection::class)
        ->and($users->pluck('id')->all())->toBe([5, 6]);
    $mock->assertSent(fn ($request): bool => $request instanceof SearchUserRequest
        && $request->query()->all() === ['query' => 'max', 'limit' => 10]);
});

it('creates a user when the search hit has a different email', function () {
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([userPayload(['email' => 'other@example.com'])]),
        CreateUserRequest::class => MockResponse::make(userPayload(['id' => 6]), 201),
    ]);

    $user = (new Zammad)->user()->searchOrCreateByEmail('max@example.com');

    expect($user->id)->toBe(6);
    $mock->assertSent(CreateUserRequest::class);
});

it('returns the search hit when the email only differs in case', function () {
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([userPayload(['email' => 'Max@Example.com'])]),
    ]);

    $user = (new Zammad)->user()->searchOrCreateByEmail('max@example.com');

    expect($user->id)->toBe(5);
    $mock->assertSentCount(1);
});

it('returns the search hit for a Unicode email case variant after trimming', function () {
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([userPayload(['email' => " \u{00C9}LODIE@EXAMPLE.COM "])]),
        CreateUserRequest::class => MockResponse::make(userPayload(['id' => 6]), 201),
    ]);

    $user = (new Zammad)->user()->searchOrCreateByEmail("\u{00E9}lodie@example.com");

    expect($user->id)->toBe(5);
    $mock->assertSentCount(1);
    $mock->assertNotSent(CreateUserRequest::class);
});

it('keeps firstname and custom attributes when create data has no email key', function () {
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([]),
        CreateUserRequest::class => MockResponse::make(userPayload(), 201),
    ]);

    (new Zammad)->user()->searchOrCreateByEmail('max@example.com', [
        'firstname' => 'Max',
        'note' => 'vip',
    ]);

    $mock->assertSentCount(2);
    $mock->assertSent(function ($request) {
        if (! $request instanceof CreateUserRequest) {
            return false;
        }

        expect($request->body()->all())->toBe([
            'firstname' => 'Max',
            'note' => 'vip',
            'email' => 'max@example.com',
        ]);

        return true;
    });
});

it('keeps custom attributes and lets the searched email win on create', function () {
    $mock = MockClient::global([
        SearchUserRequest::class => MockResponse::make([]),
        CreateUserRequest::class => MockResponse::make(userPayload(), 201),
    ]);

    (new Zammad)->user()->searchOrCreateByEmail('max@example.com', [
        'firstname' => 'Max',
        'note' => 'vip',
        'email' => 'someone-else@example.com',
    ]);

    $mock->assertSent(function ($request) {
        if (! $request instanceof CreateUserRequest) {
            return false;
        }

        $body = $request->body()->all();

        return $body['firstname'] === 'Max'
            && $body['note'] === 'vip'
            && $body['email'] === 'max@example.com';
    });
});

it('rejects a query-unsafe email before sending anything', function (string $email) {
    $mock = MockClient::global([]);

    expect(fn () => (new Zammad)->user()->searchOrCreateByEmail($email))
        ->toThrow(InvalidArgumentException::class);

    $mock->assertNothingSent();
})->with([
    'space' => 'a b@example.com',
    'quote' => 'a"b@example.com',
    'backslash' => 'a\\b@example.com',
    'open paren' => 'a(b@example.com',
    'close paren' => 'a)b@example.com',
    'star' => '*@example.com',
    'question mark' => 'a?@example.com',
    'colon' => 'a:b@example.com',
]);

it('rejects non-positive ids in show and sends nothing', function (int $id) {
    $mock = MockClient::global([]);

    expect(fn () => (new Zammad)->user()->show($id))
        ->toThrow(InvalidArgumentException::class);

    $mock->assertNothingSent();
})->with([0, -1]);

it('requests /users/5 for show(5)', function () {
    $mock = MockClient::global([
        GetUserRequest::class => MockResponse::make(userPayload()),
    ]);

    $user = (new Zammad)->user()->show(5);

    expect($user->id)->toBe(5);
    $mock->assertSent(fn ($request) => $request->resolveEndpoint() === '/users/5');
});

it('requests /users/me for me()', function () {
    $mock = MockClient::global([
        GetUserRequest::class => MockResponse::make(userPayload()),
    ]);

    (new Zammad)->user()->me();

    $mock->assertSent(fn ($request) => $request->resolveEndpoint() === '/users/me');
});

it('populates expanded on create and update when expand is on', function () {
    MockClient::global([
        UpdateUserRequest::class => MockResponse::make(userPayload(['extra' => 'x'])),
        CreateUserRequest::class => MockResponse::make(userPayload(['extra' => 'x']), 201),
    ]);

    $created = (new Zammad)->user()->expand()->create(['email' => 'max@example.com']);
    $updated = (new Zammad)->user()->expand()->update(5, ['note' => 'n']);

    expect($created->expanded)->toBeArray()->toHaveKey('extra')
        ->and($updated->expanded)->toBeArray()->toHaveKey('extra');
});

it('leaves expanded null on create and update without expand', function () {
    MockClient::global([
        UpdateUserRequest::class => MockResponse::make(userPayload()),
        CreateUserRequest::class => MockResponse::make(userPayload(), 201),
    ]);

    expect((new Zammad)->user()->create(['email' => 'max@example.com'])->expanded)->toBeNull()
        ->and((new Zammad)->user()->update(5, ['note' => 'n'])->expanded)->toBeNull();
});
