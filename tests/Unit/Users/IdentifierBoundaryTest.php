<?php

declare(strict_types=1);

use AlexHackney\Zammad\Requests\AccessTokens\DestroyAccessTokenRequest;
use AlexHackney\Zammad\Requests\Users\UpdateUserRequest;
use AlexHackney\Zammad\Resources\UserAccessToken;
use AlexHackney\Zammad\Resources\UserResource;
use AlexHackney\Zammad\Tests\Unit\Data\DtoFixtures;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

require_once __DIR__.'/../Data/DtoFixtures.php';

afterEach(function () {
    MockClient::destroyGlobal();
});

it('normalizes positive integer ids before updating users or deleting tokens', function (bool $token, int|string $id, int $expected) {
    $mock = MockClient::global([
        UpdateUserRequest::class => MockResponse::make(DtoFixtures::user()),
        DestroyAccessTokenRequest::class => MockResponse::make([]),
    ]);

    if ($token) {
        expect((new UserAccessToken)->delete($id))->toBe([]);
    } else {
        expect((new UserResource)->update($id, ['note' => 'updated'])->id)->toBe(1);
    }

    $mock->assertSentCount(1);
    $mock->assertSent(fn ($request) => $request->id === $expected
        && $request->resolveEndpoint() === ($token ? '/user_access_token/' : '/users/').$expected);
})->with([false, true])->with([
    'integer' => [5, 5],
    'route parameter' => ['5', 5],
    'whitespace' => [' 5 ', 5],
    'explicit plus' => ['+5', 5],
    'minimum' => ['1', 1],
    'maximum' => [(string) PHP_INT_MAX, PHP_INT_MAX],
]);

it('rejects invalid ids before updating users or deleting tokens', function (bool $token, int|string $id) {
    $mock = MockClient::global([]);

    expect(fn () => $token ? (new UserAccessToken)->delete($id) : (new UserResource)->update($id, []))
        ->toThrow(InvalidArgumentException::class);

    $mock->assertNothingSent();
})->with([false, true])->with([
    0, -1, '0', '-1', '', ' ', 'invalid', '5suffix', '5.0', '1e3', '05', (string) PHP_INT_MAX.'0',
]);
