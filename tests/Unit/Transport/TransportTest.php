<?php

declare(strict_types=1);

use AlexHackney\Zammad\Classes\RequestClass;
use AlexHackney\Zammad\Events\ZammadResponseLog;
use AlexHackney\Zammad\ZammadConnector;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Saloon\Config;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Http\Senders\GuzzleSender;
use Throwable;

class TransportTestRequest extends Request
{
    public function __construct(Method $method = Method::GET)
    {
        $this->method = $method;
    }

    public function resolveEndpoint(): string
    {
        return '/transport-test';
    }
}

function transportClient(): RequestClass
{
    return new class extends RequestClass {};
}

function withGuzzleTransport(array $queue, Closure $callback): void
{
    $history = [];
    $sender = new GuzzleSender;
    $sender->getHandlerStack()->setHandler(new MockHandler($queue));
    $sender->getHandlerStack()->push(Middleware::history($history));
    Config::setSenderResolver(fn () => $sender);

    // Only the in-memory Guzzle handler can run while the Saloon guard is suspended.
    Config::allowStrayRequests();

    try {
        $callback($history);
    } finally {
        Config::setSenderResolver(null);
        Config::preventStrayRequests();
    }
}

beforeEach(function () {
    config([
        'zammad.http_retry_maximum' => '3',
        'zammad.http_retry_delay' => '0',
        'zammad.object_reference_error_ignore' => false,
        'zammad.object_reference_error' => "Can't delete, object has references.",
    ]);
});

afterEach(function () {
    MockClient::destroyGlobal();
});

it('uses a constant retry delay even when exponential backoff is configured', function (bool $delete, bool $requestBackoff) {
    config(['zammad.http_retry_delay' => 17]);
    $connector = new ZammadConnector;
    $connector->useExponentialBackoff = true;
    $client = new class($connector) extends RequestClass
    {
        public function __construct(ZammadConnector $connector)
        {
            parent::__construct();
            $this->connector = $connector;
        }
    };
    $request = new TransportTestRequest(Method::DELETE);
    $request->useExponentialBackoff = $requestBackoff ? true : null;
    $mock = MockClient::global([
        MockResponse::make('', 500),
        MockResponse::make('', 500),
        MockResponse::make('', 200),
    ]);
    $sleeps = [];
    Config::sleepUsing(function (int $microseconds) use (&$sleeps) {
        $sleeps[] = $microseconds;
    });

    try {
        $response = $delete ? $client->deleteRequest($request) : $client->request($request);

        expect($response->status())->toBe(200)
            ->and($sleeps)->toBe([17000, 17000]);
        $mock->assertSentCount(3);
    } finally {
        Config::sleepUsing(null);
    }
})->with([false, true])->with([false, true]);

it('does not retry non-idempotent HTTP failures', function (Method $method, int $status) {
    $mock = MockClient::global([
        MockResponse::make('', $status),
        MockResponse::make('', 200),
    ]);

    expect(fn () => transportClient()->request(new TransportTestRequest($method)))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
})->with([Method::POST, Method::PATCH, Method::OPTIONS])->with([429, 500]);

it('retries idempotent transient HTTP failures', function (Method $method, int $status) {
    $mock = MockClient::global([
        MockResponse::make('', $status),
        MockResponse::make('', 200),
    ]);

    expect(transportClient()->request(new TransportTestRequest($method))->status())->toBe(200);

    $mock->assertSentCount(2);
})->with([Method::GET, Method::HEAD, Method::PUT, Method::DELETE])->with([429, 500]);

it('does not retry other client errors', function (int $status) {
    $mock = MockClient::global([
        MockResponse::make('', $status),
        MockResponse::make('', 200),
    ]);

    expect(fn () => transportClient()->request(new TransportTestRequest))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
})->with([400, 401, 404, 422]);

/**
 * Builds the Guzzle exception for a failed connection attempt on whichever Guzzle major is installed.
 * $kind: resolve (could not resolve host), connect (could not connect), timeout (ambiguous: the request may have
 * been sent), unknown (some other transport failure).
 */
function guzzleTransportException(string $kind, RequestInterface $request): Throwable
{
    if (method_exists(ConnectException::class, 'getHandlerContext')) {
        // Guzzle 7: everything is a ConnectException and the cURL errno tells them apart.
        $errno = ['resolve' => 6, 'connect' => 7, 'timeout' => 28, 'unknown' => null][$kind];

        return new ConnectException('Connection failed', $request, null, $errno === null ? [] : ['errno' => $errno]);
    }

    // Guzzle 8: typed exceptions.
    return match ($kind) {
        'resolve', 'connect' => new ConnectException('Connection failed', $request),
        'timeout' => new NetworkTimeoutException('Timed out', $request),
        default => new NetworkException('Transport failed', $request),
    };
}

it('retries connection failures only when safe for the method', function (Method $method, string $kind, bool $retry) {
    $attempts = 0;
    MockClient::global([
        TransportTestRequest::class => function (PendingRequest $pendingRequest) use (&$attempts, $kind) {
            $attempts++;

            if ($attempts > 1) {
                return MockResponse::make('', 200);
            }

            return MockResponse::make()->throw(fn () => new FatalRequestException(
                guzzleTransportException($kind, new PsrRequest('GET', 'https://zammad.test')),
                $pendingRequest,
            ));
        },
    ]);

    if ($retry) {
        expect(transportClient()->request(new TransportTestRequest($method))->status())->toBe(200);
    } else {
        expect(fn () => transportClient()->request(new TransportTestRequest($method)))
            ->toThrow(FatalRequestException::class);
        Event::assertNotDispatched(ZammadResponseLog::class);
    }

    expect($attempts)->toBe($retry ? 2 : 1);
})->with([
    'GET timeout' => [Method::GET, 'timeout', true],
    'GET unknown connection failure' => [Method::GET, 'unknown', true],
    'POST cannot resolve' => [Method::POST, 'resolve', true],
    'POST cannot connect' => [Method::POST, 'connect', true],
    'POST read timeout' => [Method::POST, 'timeout', false],
    'POST unknown connection failure' => [Method::POST, 'unknown', false],
    'PATCH cannot connect' => [Method::PATCH, 'connect', true],
]);

it('does not retry a non-Guzzle fatal failure for POST', function () {
    $attempts = 0;
    MockClient::global([
        TransportTestRequest::class => function (PendingRequest $pendingRequest) use (&$attempts) {
            $attempts++;

            return MockResponse::make()->throw(new FatalRequestException(new RuntimeException('Failed'), $pendingRequest));
        },
    ]);

    expect(fn () => transportClient()->request(new TransportTestRequest(Method::POST)))
        ->toThrow(FatalRequestException::class);
    expect($attempts)->toBe(1);
});

it('returns an ignored delete reference error after decoding both messages', function (string $body, string $message) {
    config([
        'zammad.object_reference_error_ignore' => true,
        'zammad.object_reference_error' => $message,
    ]);
    $mock = MockClient::global([MockResponse::make($body, 422)]);

    $response = transportClient()->deleteRequest(new TransportTestRequest(Method::DELETE));

    expect($response->status())->toBe(422);
    $mock->assertSentCount(1);
    Event::assertDispatchedTimes(ZammadResponseLog::class, 1);
    Event::assertDispatched(ZammadResponseLog::class, fn ($event) => $event->response === $response);
})->with([
    // Transport error envelope and message no DTO is hydrated.
    'encoded JSON error' => ['{"error":"Can&#39;t delete, object has references."}', "Can't delete, object has references."],
    'encoded configuration' => ['{"error":"Can\'t delete, object has references."}', 'Can&#39;t delete, object has references.'],
    'raw body fallback' => ['Details: Can&#39;t delete, object has references.', "Can't delete, object has references."],
    'JSON error contains message' => ['{"error":"Details: Can\'t delete, object has references."}', "Can't delete, object has references."],
]);

it('throws for delete errors outside the enabled reference exception', function (bool $ignore, int $status, string $body) {
    config(['zammad.object_reference_error_ignore' => $ignore]);
    $mock = MockClient::global([MockResponse::make($body, $status)]);

    expect(fn () => transportClient()->deleteRequest(new TransportTestRequest(Method::DELETE)))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    Event::assertDispatchedTimes(ZammadResponseLog::class, 1);
    Event::assertDispatched(ZammadResponseLog::class, fn ($event) => $event->response->status() === $status);
})->with([
    // Transport error envelope and message no DTO is hydrated.
    'flag off' => [false, 422, '{"error":"Can\'t delete, object has references."}'],
    'different error' => [true, 422, '{"error":"Different error"}'],
    '404 with reference message' => [true, 404, '{"error":"Can\'t delete, object has references."}'],
]);

it('throws and dispatches a response event for failed ordinary requests', function (int $tries) {
    config(['zammad.http_retry_maximum' => $tries, 'zammad.object_reference_error_ignore' => true]);
    $mock = MockClient::global([MockResponse::make('', 422)]);

    expect(fn () => transportClient()->request(new TransportTestRequest))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    Event::assertDispatchedTimes(ZammadResponseLog::class, 1);
    Event::assertDispatched(ZammadResponseLog::class, fn ($event) => $event->response->status() === 422);
})->with([1, 3]);

it('dispatches one event for a successful response', function () {
    MockClient::global([MockResponse::make('', 200)]);

    $response = transportClient()->request(new TransportTestRequest);

    Event::assertDispatchedTimes(ZammadResponseLog::class, 1);
    Event::assertDispatched(ZammadResponseLog::class, fn ($event) => $event->response === $response);
});

it('dispatches the final failed response after retries are exhausted', function () {
    $mock = MockClient::global([TransportTestRequest::class => MockResponse::make('', 500)]);

    expect(fn () => transportClient()->request(new TransportTestRequest))->toThrow(RequestException::class);

    $mock->assertSentCount(3);
    Event::assertDispatchedTimes(ZammadResponseLog::class, 1);
});

it('strips trailing slashes from the base URL', function () {
    config(['zammad.url' => 'https://zammad.test///']);
    Log::shouldReceive('warning')->never();

    expect((new ZammadConnector)->resolveBaseUrl())->toBe('https://zammad.test/api/v1');
});

it('warns once per process when HTTP would send the bearer token unencrypted', function () {
    config(['zammad.url' => 'http://zammad.test/']);
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message) => str_contains(strtolower($message), 'bearer token') && str_contains(strtolower($message), 'unencrypted')
    );

    expect((new ZammadConnector)->resolveBaseUrl())->toBe('http://zammad.test/api/v1');
    expect((new ZammadConnector)->resolveBaseUrl())->toBe('http://zammad.test/api/v1');
});

it('applies configured connection and request timeouts to pending requests', function (?string $connectTimeout, ?string $requestTimeout, float $expectedConnect, float $expectedRequest) {
    MockClient::global([MockResponse::make('', 200)]);

    if ($connectTimeout !== null) {
        config(['zammad.connect_timeout' => $connectTimeout, 'zammad.request_timeout' => $requestTimeout]);
    }

    $pendingRequest = (new ZammadConnector)->createPendingRequest(new TransportTestRequest);

    expect($pendingRequest->config()->get('connect_timeout'))->toBe($expectedConnect);
    expect($pendingRequest->config()->get('timeout'))->toBe($expectedRequest);
})->with([
    'defaults' => [null, null, 10.0, 30.0],
    'configured' => ['2.5', '12.5', 2.5, 12.5],
]);

it('keeps the missing URL and token exception messages', function () {
    config(['zammad.url' => null, 'zammad.token' => null]);

    expect(fn () => (new ZammadConnector)->resolveBaseUrl())->toThrow(Exception::class, 'No url provided.');
    expect(fn () => (new ZammadConnector)->setAuth())->toThrow(Exception::class, 'No token provided.');
});

it('never replays a POST after a redirect target connection failure', function () {
    withGuzzleTransport([
        new PsrResponse(303, ['Location' => 'https://unavailable.test/created']),
        fn ($request) => new RejectedPromise(guzzleTransportException('connect', $request)),
        new PsrResponse(201),
    ], function (array &$history) {
        $exception = null;

        try {
            transportClient()->request(new TransportTestRequest(Method::POST));
        } catch (RequestException $e) {
            $exception = $e;
        }

        $posts = array_filter($history, fn ($transaction) => $transaction['request']->getMethod() === 'POST');

        expect($posts)->toHaveCount(1);
        expect($history)->toHaveCount(1);
        expect($exception)->toBeInstanceOf(RequestException::class);
        expect($exception->getMessage())->toBe('Unexpected redirect (HTTP 303)');
    });
});

it('classifies direct connector redirects as failed responses', function (int $status) {
    $mock = MockClient::global([MockResponse::make('', $status)]);

    $response = (new ZammadConnector)->send(new TransportTestRequest);

    expect($response->failed())->toBeTrue();
    $mock->assertSentCount(1);
})->with([300, 303, 304, 399]);

it('throws the safe redirect message for direct connector responses', function () {
    $location = 'https://login.test/?token=redirect-secret';
    $mock = MockClient::global([MockResponse::make($location, 303, ['Location' => $location])]);

    $response = (new ZammadConnector)->send(new TransportTestRequest(Method::POST));

    try {
        $response->throw();
        test()->fail('Expected a redirect exception.');
    } catch (RequestException $e) {
        expect($e->getMessage())->toBe('Unexpected redirect (HTTP 303)');
        expect($e->getResponse())->toBe($response);
    }

    $mock->assertSentCount(1);
});

it('rejects redirects through Guzzle without following or retrying them', function (Method $method, bool $delete, int $status, int $tries) {
    config(['zammad.http_retry_maximum' => $tries]);
    $location = 'https://login.test/?token=redirect-secret';

    withGuzzleTransport([
        new PsrResponse($status, ['Location' => $location], $location.' test-token'),
        new PsrResponse(200, ['Content-Type' => 'text/html'], '<html>Login required</html>'),
    ], function (array &$history) use ($method, $delete, $status) {
        $client = transportClient();
        $request = new TransportTestRequest($method);

        try {
            if ($delete) {
                $client->deleteRequest($request);
            } else {
                $client->request($request);
            }

            test()->fail('Expected a redirect exception.');
        } catch (RequestException $e) {
            expect($e->getMessage())->toBe('Unexpected redirect (HTTP '.$status.')');
            expect($e->getResponse()->status())->toBe($status);
        }

        expect($history)->toHaveCount(1);
        expect($history[0]['request']->getMethod())->toBe($method->value);
        Event::assertDispatchedTimes(ZammadResponseLog::class, 1);
        Event::assertDispatched(ZammadResponseLog::class, fn ($event) => $event->response->status() === $status);
    });
})->with([
    'GET' => [Method::GET, false],
    'DELETE via request' => [Method::DELETE, false],
    'DELETE via deleteRequest' => [Method::DELETE, true],
    'POST' => [Method::POST, false],
])->with([300, 302, 303, 304, 307, 308, 399])->with([1, 3]);

it('does not ignore reference text outside a JSON string error field', function (string $body) {
    config(['zammad.object_reference_error_ignore' => true]);
    $mock = MockClient::global([MockResponse::make($body, 422)]);

    expect(fn () => transportClient()->deleteRequest(new TransportTestRequest(Method::DELETE)))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
})->with([
    'missing error' => ['{"errors":["Permission denied"],"note":"Can\'t delete, object has references."}'],
    'null error' => ['{"error":null,"note":"Can\'t delete, object has references."}'],
    'array error' => ['{"error":["Can\'t delete, object has references."]}'],
    'object error' => ['{"error":{"message":"Can\'t delete, object has references."}}'],
    'numeric error' => ['{"error":422,"note":"Can\'t delete, object has references."}'],
    'JSON array' => ['["Can\'t delete, object has references."]'],
    'JSON string' => ['"Can\'t delete, object has references."'],
]);

it('does not scan unrelated JSON fields when nesting exceeds the decoder depth', function (int $depth) {
    config(['zammad.object_reference_error_ignore' => true]);
    // Transport error envelope; no DTO is hydrated.
    $body = '{"error":"Permission denied","note":"Can\'t delete, object has references.","details":'
        .str_repeat('[', $depth).'0'.str_repeat(']', $depth).'}';
    expect(json_decode($body, true, 4096, JSON_THROW_ON_ERROR)['error'])->toBe('Permission denied');
    $mock = MockClient::global([MockResponse::make($body, 422)]);

    expect(fn () => transportClient()->deleteRequest(new TransportTestRequest(Method::DELETE)))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    Event::assertDispatchedTimes(ZammadResponseLog::class, 1);
})->with([600, 2100]);

it('fails closed even for a matching error when JSON exceeds the configured decoder depth', function () {
    config(['zammad.object_reference_error_ignore' => true]);
    // Transport error envelope; no DTO is hydrated.
    $body = '{"error":"Can\'t delete, object has references.","details":'
        .str_repeat('[', 2100).'0'.str_repeat(']', 2100).'}';
    $mock = MockClient::global([MockResponse::make($body, 422)]);

    expect(fn () => transportClient()->deleteRequest(new TransportTestRequest(Method::DELETE)))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    Event::assertDispatchedTimes(ZammadResponseLog::class, 1);
});
