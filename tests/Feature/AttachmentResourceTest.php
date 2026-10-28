<?php

declare(strict_types=1);

use AlexHackney\Zammad\Events\ZammadResponseLog;
use AlexHackney\Zammad\Requests\Attachment\GetAttachmentRequest;
use AlexHackney\Zammad\Zammad;
use Illuminate\Support\Facades\Event;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

beforeEach(function () {
    config(['zammad.http_retry_delay' => 0]);
});

afterEach(function () {
    MockClient::destroyGlobal();
});

it('can download an attachment', function () {
    $body = 'attachment-binary-content';
    $mock = MockClient::global([
        GetAttachmentRequest::class => MockResponse::make($body, 200),
    ]);

    $content = (new Zammad)->attachment()->download(
        ticketId: 32,
        commentId: 111,
        attachmentId: 42,
    );

    $this->assertSame($body, $content);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetAttachmentRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/ticket_attachment/32/111/42'
        && $request->query()->all() === []);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('attachments');

it('throws when downloading an attachment fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        GetAttachmentRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->attachment()->download(
        ticketId: 32,
        commentId: 111,
        attachmentId: 42,
    ))->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetAttachmentRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/ticket_attachment/32/111/42'
        && $request->query()->all() === []);
})->group('attachments');
