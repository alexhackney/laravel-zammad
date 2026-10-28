<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Tests\Feature;

use AlexHackney\Zammad\Data\Attachment;
use AlexHackney\Zammad\Data\Comment;
use AlexHackney\Zammad\Events\ZammadResponseLog;
use AlexHackney\Zammad\Requests\Comments\CreateCommentRequest;
use AlexHackney\Zammad\Requests\Comments\DestroyCommentRequest;
use AlexHackney\Zammad\Requests\Comments\GetCommentByTicketRequest;
use AlexHackney\Zammad\Requests\Comments\GetCommentRequest;
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

it('does show by ticket', function () {
    $id = 32;
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetCommentByTicketRequest::class => MockResponse::make([
            Mocks::comment(['id' => 66, 'ticket_id' => $id]),
        ], 200),
    ]);

    $comments = (new Zammad)->comment()->showByTicket($id);

    $this->assertInstanceOf(Collection::class, $comments);
    $this->assertCount(1, $comments);

    $comments->each(function (Comment $comment) use ($id) {
        $this->assertInstanceOf(Comment::class, $comment);
        $this->assertSame($id, $comment->ticket_id);
    });

    $mock->assertSent(fn (Request $request): bool => $request instanceof GetCommentByTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/ticket_articles/by_ticket/32'
        && $request->query()->all() === []);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('comments');

it('does show comment', function () {
    $id = 66;
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetCommentRequest::class => MockResponse::make(
            Mocks::comment(['id' => $id, 'ticket_id' => 32]),
            200,
        ),
    ]);

    $comment = (new Zammad)->comment()->show($id);

    $this->assertInstanceOf(Comment::class, $comment);
    $this->assertSame($id, $comment->id);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetCommentRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/ticket_articles/66'
        && $request->query()->all() === []);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('comments');

it('does create comment', function () {
    $data = [
        'ticket_id' => 32,
        'subject' => '::subject::',
        'body' => 'huhuhuu<br>huhuhuu<br>huhuhuu<br><br>',
        'content_type' => 'text/html',
        'attachments' => [
            [
                'filename' => 'test.txt',
                'data' => 'RHUgYmlzdCBlaW4g8J+OgSBmw7xyIGRpZSDwn4yN',
                'mime-type' => 'text/plain',
            ],
        ],
    ];

    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateCommentRequest::class => MockResponse::make(Mocks::comment([
            'id' => 66,
            'ticket_id' => 32,
            'subject' => '::subject::',
            'body' => 'huhuhuu<br>huhuhuu<br>huhuhuu<br><br>',
            'content_type' => 'text/html',
            'attachments' => [Mocks::attachment()],
        ]), 201),
        DestroyCommentRequest::class => MockResponse::make([], 200),
    ]);

    $comment = (new Zammad)->comment()->create($data);

    $this->assertInstanceOf(Comment::class, $comment);
    $this->assertSame('::subject::', $comment->subject);
    $this->assertSame('huhuhuu<br>huhuhuu<br>huhuhuu<br><br>', $comment->body);
    $this->assertSame('text/html', $comment->content_type);
    $this->assertSame(32, $comment->ticket_id);
    $this->assertCount(1, $comment->attachments);
    tap($comment->attachments->first(), function (Attachment $attachment) {
        $this->assertSame(30, $attachment->size);
        $this->assertSame('test.txt', $attachment->name);
        $this->assertSame('text/plain', $attachment->type);
    });
    Event::assertDispatched(ZammadResponseLog::class, 1);
    (new Zammad)->comment()->delete($comment->id);
    Event::assertDispatched(ZammadResponseLog::class, 2);

    $mock->assertSent(fn (Request $request): bool => $request instanceof CreateCommentRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/ticket_articles'
        && $request->query()->all() === []
        && $request->body()->all() === $data);
    $mock->assertSent(fn (Request $request): bool => $request instanceof DestroyCommentRequest
        && $request->getMethod() === Method::DELETE
        && $request->resolveEndpoint() === '/ticket_articles/66'
        && $request->query()->all() === []);
})->group('comments');

it('does parse body from comment', function () {
    $body = '<p>First paragraph of the message.</p>'
        .'<span class="js-signatureMarker"></span>'
        .'<p>Second paragraph in the signature.</p>';

    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetCommentRequest::class => MockResponse::make(
            Mocks::comment(['id' => 342, 'body' => $body, 'content_type' => 'text/html']),
            200,
        ),
    ]);

    $comment = (new Zammad)->comment()->show(342);

    $this->assertStringContainsString(
        'First paragraph of the message.',
        $comment->body,
    );
    $this->assertStringContainsString(
        'Second paragraph in the signature.',
        $comment->body,
    );
    $this->assertStringContainsString(
        'First paragraph of the message.',
        $comment->body_filtered,
    );
    $this->assertStringNotContainsString(
        'Second paragraph in the signature.',
        $comment->body_filtered,
    );
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetCommentRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/ticket_articles/342'
        && $request->query()->all() === []);
})->group('comments');

it('has a from name helper', function () {
    $id = 66;
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetCommentRequest::class => MockResponse::make(
            Mocks::comment(['id' => $id, 'from' => '"Max Mustermann" <max.mustermann@example.test>']),
            200,
        ),
    ]);

    $comment = (new Zammad)->comment()->show($id);

    $this->assertSame('Max Mustermann', $comment->fromName());
    $this->assertSame(
        Str::before(Str::between($comment->from, '"', '"'), '<'),
        $comment->fromName(),
    );
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetCommentRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/ticket_articles/66'
        && $request->query()->all() === []);
})->group('comments', 'helpers');

it('has a from email helper', function () {
    $id = 66;
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetCommentRequest::class => MockResponse::make(
            Mocks::comment(['id' => $id, 'from' => '"Max Mustermann" <max.mustermann@example.test>']),
            200,
        ),
    ]);

    $comment = (new Zammad)->comment()->show($id);

    $this->assertSame('max.mustermann@example.test', $comment->fromEmail());
    $this->assertSame(
        Str::between($comment->from, '<', '>'),
        $comment->fromEmail(),
    );
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetCommentRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/ticket_articles/66'
        && $request->query()->all() === []);
})->group('comments', 'helpers');

it('throws when showing comments by ticket fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetCommentByTicketRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->comment()->showByTicket(32))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetCommentByTicketRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/ticket_articles/by_ticket/32'
        && $request->query()->all() === []);
})->group('comments');

it('throws when showing a comment fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetCommentRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->comment()->show(66))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetCommentRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/ticket_articles/66'
        && $request->query()->all() === []);
})->group('comments');

it('throws when creating a comment fails', function () {
    $payload = ['ticket_id' => 32, 'body' => 'Message'];
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateCommentRequest::class => MockResponse::make([], 422),
    ]);

    expect(fn () => (new Zammad)->comment()->create($payload))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof CreateCommentRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/ticket_articles'
        && $request->query()->all() === []
        && $request->body()->all() === $payload);
})->group('comments');

it('throws when deleting a comment fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        DestroyCommentRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->comment()->delete(66))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof DestroyCommentRequest
        && $request->getMethod() === Method::DELETE
        && $request->resolveEndpoint() === '/ticket_articles/66'
        && $request->query()->all() === []);
})->group('comments');
