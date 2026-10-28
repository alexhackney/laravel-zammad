<?php

declare(strict_types=1);

use AlexHackney\Zammad\Data\Attachment;
use Saloon\Http\Faking\MockClient;

afterEach(function () {
    MockClient::destroyGlobal();
});

it('builds attachment urls with exactly one slash before api', function (string $baseUrl) {
    config(['zammad.url' => $baseUrl]);

    $attachment = Attachment::fake(id: 3, ticketId: 1, commentId: 2);

    expect($attachment->url())->toBe('https://zammad.test/api/v1/ticket_attachment/1/2/3');
})->with(['https://zammad.test', 'https://zammad.test/', 'https://zammad.test///']);

it('rejects missing attachment base urls like the connector', function (?string $baseUrl) {
    config(['zammad.url' => $baseUrl]);

    expect(fn () => Attachment::fake()->url())->toThrow(function (Exception $exception) {
        expect($exception::class)->toBe(Exception::class)
            ->and($exception->getMessage())->toBe('No url provided.')
            ->and($exception->getCode())->toBe(500);
    });
})->with([null, '']);

it('rejects attachment base urls without an absolute http or https scheme', function (string $baseUrl) {
    config(['zammad.url' => $baseUrl]);

    expect(fn () => Attachment::fake()->url())->toThrow(InvalidArgumentException::class);
})->with([
    'zammad.test', '/zammad', '//zammad.test', 'ftp://zammad.test', 'javascript:alert(1)',
    'data:text/html,example', 'http:relative', 'https:///missing-host', 'https://',
]);

it('allows absolute http attachment base urls and trims trailing slashes', function () {
    config(['zammad.url' => 'http://zammad.test///']);

    expect(Attachment::fake(id: 3, ticketId: 1, commentId: 2)->url())->toBe('http://zammad.test/api/v1/ticket_attachment/1/2/3');
});

it('allows underscore hosts with case insensitive http schemes', function (string $baseUrl) {
    config(['zammad.url' => $baseUrl.'///']);

    expect(Attachment::fake(id: 3, ticketId: 1, commentId: 2)->url())->toBe($baseUrl.'/api/v1/ticket_attachment/1/2/3');
})->with(['https://zammad_railsserver', 'http://zammad_railsserver', 'HTTPS://zammad_railsserver', 'HtTp://zammad_railsserver']);
