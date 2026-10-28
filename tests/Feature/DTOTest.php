<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Tests\Feature;

use AlexHackney\Zammad\Data\Attachment;
use AlexHackney\Zammad\Data\Comment;
use AlexHackney\Zammad\Data\Ticket;
use AlexHackney\Zammad\Data\User;
use Carbon\Carbon;
use InvalidArgumentException;

it('does create a fake user', function () {
    $user = User::fake();

    $this->assertInstanceOf(User::class, $user);
})->group('dto');

it('does_create_a_fake_ticket', function () {
    $ticket = Ticket::fake();

    $this->assertInstanceOf(Ticket::class, $ticket);
})->group('dto');

it('does_add_dynamic_attributes_to_ticket', function () {
    config()->set('zammad.ticket', [
        'note' => 'string',
        'note_id' => 'integer',
        'note_value' => 'float',
        'is_active' => 'boolean',
        'deleted_at' => 'datetime',
    ]);
    // derived from DTO fields, not a captured Zammad response
    $data = [
        'id' => 1,
        'number' => '1',
        'customer_id' => 1,
        'group_id' => 1,
        'state_id' => 1,
        'title' => '::title::',
        'article_count' => 0,
        'updated_at' => '2021-04-06T17:21:26.995Z',
        'created_at' => '2021-04-06T17:21:26.995Z',
        'note' => '::note::',
        'note_id' => 42,
        'note_value' => 24.2,
        'is_active' => true,
        'deleted_at' => '2021-01-21T21:01:21.995Z',
    ];

    $ticket = Ticket::fromJson($data);

    $this->assertIsString($ticket->note);
    $this->assertSame('::note::', $ticket->note);
    $this->assertIsInt($ticket->note_id);
    $this->assertSame(42, $ticket->note_id);
    $this->assertIsFloat($ticket->note_value);
    $this->assertSame(24.2, $ticket->note_value);
    $this->assertIsBool($ticket->is_active);
    $this->assertTrue($ticket->is_active);
    $this->assertInstanceOf(Carbon::class, $ticket->deleted_at);
})->group('dto');

it('does_create_a_fake_comment', function () {
    $ticket = Comment::fake();

    $this->assertInstanceOf(Comment::class, $ticket);
})->group('dto');

it('does create a fake attachment', function () {
    $attachment = Attachment::fake();

    $this->assertInstanceOf(Attachment::class, $attachment);
})->group('dto');

it('can get a url of an attachment', function () {
    // derived from DTO fields, not a captured Zammad response
    $attachment = Attachment::fromJson(
        [
            'id' => 42,
            'size' => 37055,
            'filename' => 'fake.txt',
            'preferences' => [
                'Content-Type' => 'text/plain',
                'Mime-Type' => 'text/plain',
            ],
        ],
        ticketId: 32,
        commentId: 111,
    );

    expect($attachment->url())->toBe('https://zammad.test/api/v1/ticket_attachment/32/111/42');

    config(['zammad.url' => 'https://zammad.test/']);
    expect($attachment->url())->toBe('https://zammad.test/api/v1/ticket_attachment/32/111/42');

    config(['zammad.url' => 'relative-url']);
    expect(fn () => $attachment->url())->toThrow(InvalidArgumentException::class);
})->group('attachments');

it('can get the status of a ticket', function () {
    $ticket = Ticket::fake(state_id: 1);
    expect($ticket->state())->toBe('new');

    $ticket = Ticket::fake(state_id: 2);
    expect($ticket->state())->toBe('open');

    $ticket = Ticket::fake(state_id: 3);
    expect($ticket->state())->toBe('pending_reminder');

    $ticket = Ticket::fake(state_id: 4);
    expect($ticket->state())->toBe('closed');

    $ticket = Ticket::fake(state_id: 5);
    expect($ticket->state())->toBe('merged');

    $ticket = Ticket::fake(state_id: 6);
    expect($ticket->state())->toBe('removed');

    $ticket = Ticket::fake(state_id: 7);
    expect($ticket->state())->toBe('pending_close');

    $ticket = Ticket::fake(state_id: 8);
    expect($ticket->state())->toBe('unknown');

})->group('tickets');
