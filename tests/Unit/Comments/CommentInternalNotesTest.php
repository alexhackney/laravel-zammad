<?php

declare(strict_types=1);

use AlexHackney\Zammad\Data\Comment;
use AlexHackney\Zammad\Data\Ticket;
use AlexHackney\Zammad\Requests\Comments\GetCommentByTicketRequest;
use AlexHackney\Zammad\Requests\Tickets\GetTicketRequest;
use AlexHackney\Zammad\Resources\CommentResource;
use AlexHackney\Zammad\Resources\TicketResource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// derived from DTO fields, not a captured Zammad response
if (! function_exists('derivedCommentPayload')) {
    function derivedCommentPayload(int $id, bool $internal, int $ticketId = 42): array
    {
        return [
            'id' => $id,
            'type_id' => 10,
            'ticket_id' => $ticketId,
            'sender_id' => 1,
            'sender' => 'Customer',
            'subject' => 'Subject '.$id,
            'body' => 'Body '.$id,
            'content_type' => 'text/html',
            'from' => 'Customer <customer@example.com>',
            'to' => 'Agent <agent@example.com>',
            'internal' => $internal,
            'created_by_id' => 1,
            'updated_by_id' => 1,
            'origin_by_id' => null,
            'attachments' => [],
            'updated_at' => '2026-01-01T00:00:00.000Z',
            'created_at' => '2026-01-01T00:00:00.000Z',
        ];
    }
}

// derived from DTO fields, not a captured Zammad response
if (! function_exists('derivedTicketPayload')) {
    function derivedTicketPayload(int $id = 42): array
    {
        return [
            'id' => $id,
            'number' => 10000 + $id,
            'customer_id' => 1,
            'group_id' => 1,
            'state_id' => 1,
            'title' => 'Test Ticket',
            'article_count' => 2,
            'updated_at' => '2026-01-01T00:00:00.000Z',
            'created_at' => '2026-01-01T00:00:00.000Z',
        ];
    }
}

afterEach(function () {
    MockClient::destroyGlobal();
});

it('excludes internal articles before DTO hydration', function (bool $withTicket) {
    // derived from DTO fields, not a captured Zammad response
    $internal = derivedCommentPayload(id: 1, internal: true);
    $internal['created_at'] = 'invalid-date-for-hydration';

    MockClient::global([
        GetTicketRequest::class => MockResponse::make(derivedTicketPayload(id: 42)),
        GetCommentByTicketRequest::class => MockResponse::make([
            $internal,
            derivedCommentPayload(id: 2, internal: false),
        ]),
    ]);

    $comments = $withTicket
        ? (new TicketResource)->showWithComments(42)->comments
        : (new CommentResource)->showByTicket(42);

    expect($comments)->toHaveCount(1);
    expect($comments->first())->toBeInstanceOf(Comment::class);
    expect($comments->first()->id)->toBe(2);
    expect($comments->keys()->all())->toBe([0]);
})->with([false, true]);

it('excludes internal comments by default in showByTicket and reindexes collection', function () {
    MockClient::global([
        GetCommentByTicketRequest::class => MockResponse::make([
            derivedCommentPayload(id: 1, internal: true),
            derivedCommentPayload(id: 2, internal: false),
        ], 200),
    ]);

    $comments = (new CommentResource)->showByTicket(42);

    expect($comments)->toHaveCount(1);
    expect($comments->first()->id)->toBe(2);
    expect($comments->first()->internal)->toBeFalse();
    expect($comments->keys()->all())->toBe([0]);
});

it('includes internal comments when includeInternal is true in showByTicket', function () {
    MockClient::global([
        GetCommentByTicketRequest::class => MockResponse::make([
            derivedCommentPayload(id: 1, internal: true),
            derivedCommentPayload(id: 2, internal: false),
        ], 200),
    ]);

    $comments = (new CommentResource)->showByTicket(42, includeInternal: true);

    expect($comments)->toHaveCount(2);
    expect($comments->pluck('id')->all())->toBe([1, 2]);
    expect($comments->pluck('internal')->all())->toBe([true, false]);
});

it('excludes internal comments by default in showWithComments', function () {
    MockClient::global([
        GetTicketRequest::class => MockResponse::make(derivedTicketPayload(id: 42), 200),
        GetCommentByTicketRequest::class => MockResponse::make([
            derivedCommentPayload(id: 1, internal: true),
            derivedCommentPayload(id: 2, internal: false),
        ], 200),
    ]);

    $ticket = (new TicketResource)->showWithComments(42);

    expect($ticket)->toBeInstanceOf(Ticket::class);
    expect($ticket->comments)->toHaveCount(1);
    expect($ticket->comments->first()->id)->toBe(2);
    expect($ticket->comments->first()->internal)->toBeFalse();
    expect($ticket->comments->keys()->all())->toBe([0]);
});

it('includes internal comments when includeInternal is true in showWithComments', function () {
    MockClient::global([
        GetTicketRequest::class => MockResponse::make(derivedTicketPayload(id: 42), 200),
        GetCommentByTicketRequest::class => MockResponse::make([
            derivedCommentPayload(id: 1, internal: true),
            derivedCommentPayload(id: 2, internal: false),
        ], 200),
    ]);

    $ticket = (new TicketResource)->showWithComments(42, includeInternal: true);

    expect($ticket)->toBeInstanceOf(Ticket::class);
    expect($ticket->comments)->toHaveCount(2);
    expect($ticket->comments->pluck('id')->all())->toBe([1, 2]);
    expect($ticket->comments->pluck('internal')->all())->toBe([true, false]);
});
