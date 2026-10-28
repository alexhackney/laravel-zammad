<?php

declare(strict_types=1);

use AlexHackney\Zammad\Commands\PingCommand;
use AlexHackney\Zammad\Requests\Users\GetUserRequest;
use AlexHackney\Zammad\Testing\FakeZammad;
use AlexHackney\Zammad\ZammadConnector;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Senders\GuzzleSender;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    FakeZammad::reset();
    config(['zammad.http_retry_delay' => 0, 'zammad.http_retry_maximum' => 1]);
});

afterEach(function (): void {
    FakeZammad::reset();
    Config::setSenderResolver(null);
    Config::preventStrayRequests();
});

it('reports the connected host and user login', function (): void {
    FakeZammad::fake([
        GetUserRequest::class => MockResponse::make(pingUserPayload()),
    ]);

    $this->artisan('zammad:ping')
        ->expectsOutput('Connected to zammad.test as ada@example.test')
        ->assertExitCode(0);

    FakeZammad::assertSent(GetUserRequest::class);
    FakeZammad::assertSentCount(1);
});

it('reports request failures without exposing the token', function (int $status): void {
    config(['zammad.token' => 'secret-token']);
    FakeZammad::fake([GetUserRequest::class => MockResponse::make(['error' => 'failure'], $status)]);

    $this->artisan('zammad:ping')
        ->expectsOutput('HTTP '.$status)
        ->doesntExpectOutput('secret-token')
        ->assertExitCode(1);

    FakeZammad::assertSent(GetUserRequest::class);
})->with([401, 500]);

it('fails clearly when the token is missing without sending a request', function (): void {
    config(['zammad.token' => null]);
    FakeZammad::fake();

    $this->artisan('zammad:ping')
        ->expectsOutput('Zammad token is not configured.')
        ->assertExitCode(1);

    FakeZammad::assertNothingSent();
});

it('writes successful output as JSON', function (): void {
    FakeZammad::fake([GetUserRequest::class => MockResponse::make(pingUserPayload())]);

    $this->artisan('zammad:ping --json')
        ->expectsOutput('{"ok":true,"login":"ada@example.test","id":42}')
        ->assertExitCode(0);

    FakeZammad::assertSent(GetUserRequest::class);
});

it('writes failures as JSON', function (): void {
    config(['zammad.token' => null]);
    FakeZammad::fake();

    $this->artisan('zammad:ping --json')
        ->expectsOutput('{"ok":false,"error":"Zammad token is not configured."}')
        ->assertExitCode(1);
});

it('withholds every secret fragment when Guzzle rejects a normalized token', function (bool $json, string $token): void {
    config(['zammad.token' => $token]);
    $handler = pingGuzzleHandler([]);

    [$status, $output] = runPingCommand($json);

    expect($status)->toBe(1);
    foreach ([$token, 'private-token', 'private-suffix', 'Bearer', 'Stack trace'] as $secret) {
        expect($output)->not->toContain($secret);
    }
    expect(count($handler))->toBe(0);
    assertPingFailureOutput($output, $json);
    expect(pingErrorMessage($output, $json))->toBe('Unexpected InvalidArgumentException error (details withheld)');
})->with([false, true])->with([
    'newline' => "private-token\nprivate-suffix",
    'newline and space' => "private-token\nprivate-suffix ",
    'newline and tab' => "private-token\nprivate-suffix\t",
    'CRLF and space' => "private-token\r\nprivate-suffix ",
    'CRLF and tab' => "private-token\r\nprivate-suffix\t",
    'quotes and backslash' => "private-token\nprivate-suffix\"\\ ",
]);

it('handles fatal connection failures safely', function (bool $json, int $errno): void {
    $message = 'Connection failed; bEaReR another-secret; Bearer "quoted-secret"; Bearer <wrapped-secret>; Authorization: Basic header-secret';
    $handler = pingGuzzleHandler([
        new ConnectException($message, new Request('GET', 'https://zammad.test/api/v1/users/me'), null, ['errno' => $errno]),
    ]);

    [$status, $output] = runPingCommand($json);

    expect($status)->toBe(1);
    foreach (['another-secret', 'quoted-secret', 'wrapped-secret', 'header-secret', 'Stack trace'] as $secret) {
        expect($output)->not->toContain($secret);
    }
    expect(count($handler))->toBe(0);
    assertPingFailureOutput($output, $json);
    expect(pingErrorMessage($output, $json))->toBe('Connection failed (ConnectException)');
})->with([false, true])->with(['DNS' => 6, 'connection' => 7, 'TLS' => 60]);

it('handles a successful HTML response as a failure', function (bool $json): void {
    pingGuzzleHandler([new Response(200, ['Content-Type' => 'text/html'], '<html>Login page</html>')]);

    [$status, $output] = runPingCommand($json);

    expect($status)->toBe(1);
    assertPingFailureOutput($output, $json);
    expect(pingErrorMessage($output, $json))->toBe('Unexpected JsonException error (details withheld)');
})->with([false, true]);

it('handles a DTO hydration TypeError as a failure', function (bool $json): void {
    $payload = pingUserPayload();
    $payload['id'] = [];
    FakeZammad::fake([GetUserRequest::class => MockResponse::make($payload)]);

    [$status, $output] = runPingCommand($json);

    expect($status)->toBe(1);
    assertPingFailureOutput($output, $json);
    expect(pingErrorMessage($output, $json))->toBe('Unexpected TypeError error (details withheld)');
})->with([false, true]);

it('accepts the token zero and sends it as authorization', function (bool $json): void {
    config(['zammad.token' => '0']);
    $mock = FakeZammad::fake([GetUserRequest::class => MockResponse::make(pingUserPayload())]);

    [$status, $output] = runPingCommand($json);

    expect($status)->toBe(0);
    $mock->assertSent(GetUserRequest::class);
    expect($mock->getLastPendingRequest()?->headers()->get('Authorization'))->toBe('Bearer 0');
    expect($output)->toBe($json
        ? '{"ok":true,"login":"ada@example.test","id":42}'.PHP_EOL
        : 'Connected to zammad.test as ada@example.test'.PHP_EOL);
})->with([false, true]);

it('fails when the URL is missing without sending a request', function (bool $json): void {
    config(['zammad.url' => null]);
    FakeZammad::fake();

    [$status, $output] = runPingCommand($json);

    expect($status)->toBe(1)
        ->and($output)->toBe($json
            ? '{"ok":false,"error":"Zammad URL is not configured."}'.PHP_EOL
            : 'Zammad URL is not configured.'.PHP_EOL);
    FakeZammad::assertNothingSent();
})->with([false, true]);

it('withholds arbitrary exception details', function (bool $json, Throwable $exception): void {
    $token = 'secret-token';
    config(['zammad.token' => $token]);
    MockClient::global([
        GetUserRequest::class => fn () => throw $exception,
    ]);

    [$status, $output] = runPingCommand($json);

    expect($status)->toBe(1)->and($output)->not->toContain($token);
    expect(pingErrorMessage($output, $json))->toBe('Unexpected '.class_basename($exception).' error (details withheld)')
        ->and($output)->not->toContain('private-detail');
    expect(mb_strlen(pingErrorMessage($output, $json)))->toBeLessThanOrEqual(200);
    assertPingFailureOutput($output, $json);
})->with([false, true])->with([
    new RuntimeException(str_repeat('x', 195).'secret-token private-detail'.str_repeat('y', 100)),
    new InvalidArgumentException('secret-token private-detail'),
    new TypeError('secret-token private-detail'),
    new JsonException('secret-token private-detail'),
    new Exception('No url provided.', 500),
    new Exception('No token provided.', 500),
    new RuntimeException('No token provided.', 500),
]);

it('redacts a token echoed in the successful login', function (bool $json, string $login, string $expected): void {
    config(['zammad.token' => 'secret-token']);
    $payload = pingUserPayload();
    $payload['login'] = $login;
    FakeZammad::fake([GetUserRequest::class => MockResponse::make($payload)]);

    [$status, $output] = runPingCommand($json);

    expect($status)->toBe(0)->and($output)->not->toContain('secret-token', 'other-secret');
    expect($output)->toBe($json
        ? json_encode(['ok' => true, 'login' => $expected, 'id' => 42], JSON_THROW_ON_ERROR).PHP_EOL
        : 'Connected to zammad.test as '.$expected.PHP_EOL);
})->with([false, true])->with([
    ['secret-token', '[REDACTED]'],
    ['user-secret-token@example.test', 'user-[REDACTED]@example.test'],
    ['bEaReR other-secret', '[REDACTED]'],
    ['Authorization: Basic other-secret', 'Authorization: [REDACTED]'],
]);

it('handles throwing Stringable configuration without exposing its message', function (bool $json, string $key): void {
    config([$key => new class implements Stringable
    {
        public function __toString(): string
        {
            throw new RuntimeException('private-detail from Stringable');
        }
    }]);
    FakeZammad::fake();

    [$status, $output] = runPingCommand($json);

    expect($status)->toBe(1)->and($output)->not->toContain('private-detail', 'Stack trace');
    assertPingFailureOutput($output, $json);
    expect(pingErrorMessage($output, $json))->toBe('Unexpected RuntimeException error (details withheld)');
    FakeZammad::assertNothingSent();
})->with([false, true])->with(['zammad.url', 'zammad.token']);

it('allows only the fixed configuration errors originating in the connector', function (bool $json, string $key, string $method, string $expected): void {
    MockClient::global([
        GetUserRequest::class => function () use ($key, $method): never {
            config([$key => null]);
            (new ZammadConnector)->{$method}();
            throw new LogicException('Expected the connector to reject missing configuration.');
        },
    ]);

    [$status, $output] = runPingCommand($json);

    expect($status)->toBe(1);
    assertPingFailureOutput($output, $json);
    expect(pingErrorMessage($output, $json))->toBe($expected);
})->with([false, true])->with([
    ['zammad.url', 'resolveBaseUrl', 'No url provided.'],
    ['zammad.token', 'setAuth', 'No token provided.'],
]);

function pingErrorMessage(string $output, bool $json): string
{
    return $json ? json_decode($output, true, flags: JSON_THROW_ON_ERROR)['error'] : trim($output);
}

/** @param array<int, Response|ConnectException> $queue */
function pingGuzzleHandler(array $queue): MockHandler
{
    $handler = new MockHandler($queue);
    $sender = new GuzzleSender;
    $sender->getHandlerStack()->setHandler($handler);
    Config::setSenderResolver(fn (): GuzzleSender => $sender);
    // All transport calls use the in-memory handler above; no network is used.
    Config::allowStrayRequests();

    return $handler;
}

/** @return array{int, string} */
function runPingCommand(bool $json): array
{
    $command = new PingCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $status = $tester->execute($json ? ['--json' => true] : [], ['capture_stderr_separately' => true]);

    expect($tester->getErrorOutput())->toBe('');

    return [$status, $tester->getDisplay()];
}

function assertPingFailureOutput(string $output, bool $json): void
{
    if ($json) {
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_keys($data))->toBe(['ok', 'error'])
            ->and($data['ok'])->toBeFalse()
            ->and($data['error'])->toBeString()->not->toBe('')
            ->and($output)->toBe(json_encode($data, JSON_THROW_ON_ERROR).PHP_EOL);
    } else {
        expect(trim($output))->not->toBe('');
    }
}

/** @return array<string, mixed> */
function pingUserPayload(): array
{
    return [
        // derived from DTO fields, not a captured Zammad response
        'id' => 42,
        'firstname' => 'Ada',
        'lastname' => 'Lovelace',
        'login' => 'ada@example.test',
        'email' => 'ada@example.test',
        'last_login' => '2024-01-01T10:00:00Z',
        'updated_at' => '2024-01-01T10:00:00Z',
        'created_at' => '2023-01-01T10:00:00Z',
    ];
}
