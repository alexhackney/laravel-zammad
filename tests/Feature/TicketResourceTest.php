<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Tests\Feature;

use AlexHackney\Zammad\Data\Comment;
use AlexHackney\Zammad\Data\Ticket;
use AlexHackney\Zammad\Events\ZammadResponseLog;
use AlexHackney\Zammad\Requests\Comments\GetCommentByTicketRequest;
use AlexHackney\Zammad\Requests\Tickets\AllTicketsRequest;
use AlexHackney\Zammad\Requests\Tickets\CreateTicketRequest;
use AlexHackney\Zammad\Requests\Tickets\DestroyTicketRequest;
use AlexHackney\Zammad\Requests\Tickets\GetTicketRequest;
use AlexHackney\Zammad\Requests\Tickets\SearchTicketRequest;
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

it('lists tickets', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllTicketsRequest::class => MockResponse::make([
            Mocks::ticket(['id' => 32]),
        ], 200),
    ]);

    $tickets = (new Zammad)->ticket()->list();

    $this->assertInstanceOf(Collection::class, $tickets);
    $this->assertCount(1, $tickets);
    $tickets->each(function (Ticket $ticket) {
        $this->assertInstanceOf(Ticket::class, $ticket);
        $this->assertSame(32, $ticket->id);
    });

    $mock->assertSent(fn (Request $request): bool => $request instanceof AllTicketsRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets'
        && $request->query()->all() === []);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('tickets');

it('searches tickets', function () {
    $term = 'fix';
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        SearchTicketRequest::class => MockResponse::make([
            'assets' => [
                'Ticket' => [
                    Mocks::ticket(['id' => 32, 'title' => 'fix']),
                ],
            ],
        ], 200),
    ]);

    $tickets = (new Zammad)->ticket()->search($term);

    $this->assertInstanceOf(Collection::class, $tickets);
    $this->assertCount(1, $tickets);
    $tickets->each(function (Ticket $ticket) {
        $this->assertInstanceOf(Ticket::class, $ticket);
        $this->assertSame(32, $ticket->id);
        $this->assertSame('fix', $ticket->subject);
    });
    $mock->assertSent(fn (Request $request): bool => $request instanceof SearchTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets/search'
        && $request->query()->all() === ['query' => 'fix']);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('tickets');

it('searches tickets with empty result', function () {
    $term = '::this-should-return-null::';
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        SearchTicketRequest::class => MockResponse::make([
            'assets' => [
                'Ticket' => [],
            ],
        ], 200),
    ]);

    $tickets = (new Zammad)->ticket()->search($term);

    $this->assertInstanceOf(Collection::class, $tickets);
    $this->assertCount(0, $tickets);
    $mock->assertSent(fn (Request $request): bool => $request instanceof SearchTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets/search'
        && $request->query()->all() === ['query' => '::this-should-return-null::']);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('tickets');

it('shows a ticket', function () {
    $id = 32;
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetTicketRequest::class => MockResponse::make(Mocks::ticket(['id' => $id]), 200),
    ]);

    $ticket = (new Zammad)->ticket()->show($id);

    $this->assertInstanceOf(Ticket::class, $ticket);
    $this->assertSame($id, $ticket->id);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets/32'
        && $request->query()->all() === ['expand' => false]);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('tickets');

it('shows a ticket with comments', function () {
    $id = 32;
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetTicketRequest::class => MockResponse::make(Mocks::ticket(['id' => $id]), 200),
        GetCommentByTicketRequest::class => MockResponse::make([
            Mocks::comment(['id' => 66, 'ticket_id' => $id, 'internal' => false]),
        ], 200),
    ]);

    $ticket = (new Zammad)->ticket()->showWithComments($id);

    $this->assertInstanceOf(Ticket::class, $ticket);
    $this->assertSame($id, $ticket->id);
    $this->assertInstanceOf(Collection::class, $ticket->comments);
    $this->assertTrue($ticket->comments->count() > 0);
    $this->assertInstanceOf(Comment::class, $ticket->comments->first());
    $this->assertSame(66, $ticket->comments->first()->id);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets/32'
        && $request->query()->all() === ['expand' => false]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetCommentByTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/ticket_articles/by_ticket/32'
        && $request->query()->all() === []);
    Event::assertDispatched(ZammadResponseLog::class, 2);
})->group('tickets');

it('create and delete a ticket', function () {
    $data = [
        'title' => '::title::',
        'group' => 'Inbox',
        'customer' => 'sebastian.fix@example.test',
        'article' => [
            'body' => '::body::',
            'type' => 'note',
            'internal' => false,
        ],
        'house' => 20,
    ];

    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateTicketRequest::class => MockResponse::make(Mocks::ticket([
            'id' => 32,
            'title' => '::title::',
            'customer_id' => 4,
        ]), 201),
        DestroyTicketRequest::class => MockResponse::make([], 200),
    ]);

    $ticket = (new Zammad)->ticket()->create($data);

    $this->assertInstanceOf(Ticket::class, $ticket);
    $this->assertSame('::title::', $ticket->subject);

    // 4 customer_id => Sebastian Fix
    $this->assertSame(4, $ticket->customer_id);
    Event::assertDispatched(ZammadResponseLog::class, 1);

    (new Zammad)->ticket()->delete($ticket->id);
    Event::assertDispatched(ZammadResponseLog::class, 2);

    $mock->assertSent(fn (Request $request): bool => $request instanceof CreateTicketRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/tickets'
        && $request->query()->all() === ['expand' => false]
        && $request->body()->all() === $data);
    $mock->assertSent(fn (Request $request): bool => $request instanceof DestroyTicketRequest
        && $request->getMethod() === Method::DELETE
        && $request->resolveEndpoint() === '/tickets/32'
        && $request->query()->all() === []);
})->group('tickets');

it('shows a ticket expanded', function () {
    $id = 32;
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetTicketRequest::class => MockResponse::make(Mocks::ticket(['id' => $id]), 200),
    ]);

    $ticket = (new Zammad)->ticket()->show($id);
    $ticketExpand = (new Zammad)->ticket()->expand()->show($id);

    $this->assertInstanceOf(Ticket::class, $ticket);
    $this->assertInstanceOf(Ticket::class, $ticketExpand);
    Event::assertDispatched(ZammadResponseLog::class, 2);

    $this->assertSame($ticket->id, $ticketExpand->id);
    $this->assertNull($ticket->expanded);
    $this->assertNotNull($ticketExpand->expanded);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets/32'
        && $request->query()->all() === ['expand' => false]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets/32'
        && $request->query()->all() === ['expand' => true]);
})->group('tickets', 'expand');

it('paginates ticket list', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllTicketsRequest::class => MockResponse::make([Mocks::ticket(['id' => 32])], 200),
    ]);

    $users = (new Zammad)->ticket()->paginate(1, 2)->list();
    $usersTwo = (new Zammad)->ticket()->paginate(2, 2)->list();

    $this->assertNotSame($users, $usersTwo);
    $this->assertInstanceOf(Ticket::class, $users->first());
    $this->assertInstanceOf(Ticket::class, $usersTwo->first());
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllTicketsRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets'
        && $request->query()->all() === ['per_page' => 2, 'page' => 1]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllTicketsRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets'
        && $request->query()->all() === ['per_page' => 2, 'page' => 2]);
})->group('tickets', 'paginate');

it('paginates ticket list with page and perPage methods', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllTicketsRequest::class => MockResponse::make([Mocks::ticket(['id' => 32])], 200),
    ]);

    $tickets = (new Zammad)->ticket()->page(1)->perPage(2)->list();
    $ticketsTwo = (new Zammad)->ticket()->page(2)->perPage(2)->list();

    $this->assertNotSame($tickets, $ticketsTwo);
    $this->assertInstanceOf(Ticket::class, $tickets->first());
    $this->assertInstanceOf(Ticket::class, $ticketsTwo->first());
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllTicketsRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets'
        && $request->query()->all() === ['per_page' => 2, 'page' => 1]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllTicketsRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets'
        && $request->query()->all() === ['per_page' => 2, 'page' => 2]);
})->group('tickets', 'paginate');

it('limits and paginates ticket search', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        SearchTicketRequest::class => MockResponse::make([
            'assets' => [
                'Ticket' => [Mocks::ticket(['id' => 32])],
            ],
        ], 200),
    ]);

    $tickets = (new Zammad)->ticket()->limit(5)->paginate(2, 10)->search('fix');

    $this->assertCount(1, $tickets);
    $this->assertInstanceOf(Ticket::class, $tickets->first());
    $mock->assertSent(fn (Request $request): bool => $request instanceof SearchTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets/search'
        && $request->query()->all() === [
            'query' => 'fix',
            'limit' => 5,
            'per_page' => 10,
            'page' => 2,
        ]);
})->group('tickets', 'limit', 'paginate');

it('throws when listing tickets fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllTicketsRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->ticket()->list())
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllTicketsRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets'
        && $request->query()->all() === []);
})->group('tickets');

it('throws when searching tickets fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        SearchTicketRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->ticket()->search('fix'))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof SearchTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets/search'
        && $request->query()->all() === ['query' => 'fix']);
})->group('tickets');

it('throws when showing a ticket fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetTicketRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->ticket()->show(32))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets/32'
        && $request->query()->all() === ['expand' => false]);
})->group('tickets');

it('throws when showing a ticket with comments fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetTicketRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->ticket()->showWithComments(32))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/tickets/32'
        && $request->query()->all() === ['expand' => false]);
})->group('tickets');

it('throws when creating a ticket fails', function () {
    $payload = ['title' => '::title::'];
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateTicketRequest::class => MockResponse::make([], 422),
    ]);

    expect(fn () => (new Zammad)->ticket()->create($payload))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof CreateTicketRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/tickets'
        && $request->query()->all() === ['expand' => false]
        && $request->body()->all() === $payload);
})->group('tickets');

it('throws when deleting a ticket fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        DestroyTicketRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->ticket()->delete(32))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof DestroyTicketRequest
        && $request->getMethod() === Method::DELETE
        && $request->resolveEndpoint() === '/tickets/32'
        && $request->query()->all() === []);
})->group('tickets');
