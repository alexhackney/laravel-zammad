<?php

declare(strict_types=1);

use AlexHackney\Zammad\Data\Comment;
use AlexHackney\Zammad\Data\Ticket;
use AlexHackney\Zammad\Tests\Unit\Data\DtoFixtures;
use Carbon\Carbon;
use Saloon\Http\Faking\MockClient;

require_once __DIR__.'/DtoFixtures.php';

afterEach(function () {
    MockClient::destroyGlobal();
});

it('keeps the supplied comments collection', function () {
    $comments = collect([Comment::fake(id: 3)]);

    $ticket = Ticket::fake(comments: $comments);

    expect($ticket->comments)->toBe($comments)
        ->and($ticket->comments->first()->id)->toBe(3);
});

it('hydrates comments arrays into comment DTOs', function () {
    $ticket = Ticket::fromJson(DtoFixtures::ticket(['comments' => [DtoFixtures::comment()]]));

    expect($ticket->comments)->toHaveCount(1)
        ->and($ticket->comments->first())->toBeInstanceOf(Comment::class)
        ->and($ticket->comments->first()->id)->toBe(3);
});

it('keeps a comments collection passed through fromJson', function () {
    $comments = collect([Comment::fake(id: 3)]);

    expect(Ticket::fromJson(DtoFixtures::ticket(['comments' => $comments]))->comments)->toBe($comments);
});

it('normalizes each comment regardless of its container', function (bool $collection, bool $hydrated) {
    $comment = $hydrated ? Comment::fake(id: 3) : DtoFixtures::comment();
    $comments = $collection ? collect([$comment]) : [$comment];

    $ticket = Ticket::fromJson(DtoFixtures::ticket(['comments' => $comments]));

    expect($ticket->comments)->toHaveCount(1)
        ->and($ticket->comments->first())->toBeInstanceOf(Comment::class)
        ->and($ticket->comments->first()->id)->toBe(3);

    if ($hydrated) {
        expect($ticket->comments->first())->toBe($comment);
    }
})->with([
    'array of arrays' => [false, false],
    'collection of arrays' => [true, false],
    'array of DTOs' => [false, true],
    'collection of DTOs' => [true, true],
]);

it('normalizes mixed comment elements and preserves existing DTOs', function () {
    $comment = Comment::fake(id: 4);

    $ticket = Ticket::fromJson(DtoFixtures::ticket(['comments' => [DtoFixtures::comment(), $comment]]));

    expect($ticket->comments->first())->toBeInstanceOf(Comment::class)
        ->and($ticket->comments->last())->toBe($comment);
});

it('does not overwrite declared properties with custom attributes', function () {
    config(['zammad.ticket' => ['created_at' => 'string', 'subject' => 'string']]);

    $ticket = Ticket::fromJson(DtoFixtures::ticket(['subject' => 'Custom subject']));

    expect($ticket->created_at)->toBeInstanceOf(Carbon::class)
        ->and($ticket->created_at->toDateString())->toBe('2026-01-01')
        ->and($ticket->subject)->toBe('Example ticket');
});

it('casts configured custom attributes to strings', function () {
    config(['zammad.ticket' => ['custom_reference' => 'string']]);

    expect(Ticket::fromJson(DtoFixtures::ticket(['custom_reference' => 42]))->custom_reference)->toBe('42');
});

it('parses boolean custom attributes with a truthiness fallback', function (mixed $value, bool $expected) {
    config(['zammad.ticket' => ['custom_flag' => 'boolean']]);

    expect(Ticket::fromJson(DtoFixtures::ticket(['custom_flag' => $value]))->custom_flag)->toBe($expected);
})->with([
    ['false', false], ['0', false], ['no', false], ['off', false], ['', false],
    ['true', true], ['1', true], ['yes', true], ['on', true], ['other', true],
    [false, false], [true, true],
]);

it('allows public dynamic ticket attributes without deprecation', function () {
    expect((new ReflectionClass(Ticket::class))->getAttributes(AllowDynamicProperties::class))->toHaveCount(1);
});
