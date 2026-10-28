<?php

declare(strict_types=1);

use AlexHackney\Zammad\Requests\Tickets\CreateTicketRequest;
use AlexHackney\Zammad\Zammad;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// derived from DTO fields, not a captured Zammad response
function ticketPayload(): array
{
    return [
        'id' => 20,
        'number' => 20001,
        'customer_id' => 3,
        'group_id' => 1,
        'state_id' => 2,
        'title' => 'Help',
        'updated_at' => '2024-01-02T10:00:00.000Z',
        'created_at' => '2024-01-01T09:00:00.000Z',
        'extra' => 'x',
    ];
}

beforeEach(function () {
    config()->set('zammad.http_retry_delay', 0);
});

afterEach(function () {
    MockClient::destroyGlobal();
});

it('populates expanded on ticket create when expand is on', function () {
    MockClient::global([CreateTicketRequest::class => MockResponse::make(ticketPayload(), 201)]);

    $ticket = (new Zammad)->ticket()->expand()->create(['title' => 'Help']);

    expect($ticket->expanded)->toBeArray()->toHaveKey('extra');
});

it('leaves expanded null on ticket create without expand', function () {
    MockClient::global([CreateTicketRequest::class => MockResponse::make(ticketPayload(), 201)]);

    expect((new Zammad)->ticket()->create(['title' => 'Help'])->expanded)->toBeNull();
});
