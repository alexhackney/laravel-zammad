<?php

declare(strict_types=1);

use AlexHackney\Zammad\Data\Attachment;
use AlexHackney\Zammad\Data\Comment;
use AlexHackney\Zammad\Data\Ticket;
use AlexHackney\Zammad\Tests\Unit\Data\DtoFixtures;
use Illuminate\Support\LazyCollection;

require_once __DIR__.'/DtoFixtures.php';

it('hydrates nested DTOs from arrays collections and traversables', function (bool $attachments, string $container) {
    $items = [7 => $attachments ? DtoFixtures::attachment() : DtoFixtures::comment()];
    $input = match ($container) {
        'array' => $items,
        'collection' => collect($items),
        'iterator' => new ArrayIterator($items),
        'generator' => (function () use ($items) {
            yield from $items;
        })(),
        'lazy collection' => new LazyCollection($items),
    };

    $nested = $attachments
        ? Comment::fromJson(DtoFixtures::comment(['attachments' => $input]))->attachments
        : Ticket::fromJson(DtoFixtures::ticket(['comments' => $input]))->comments;

    expect($nested)->toHaveCount(1)
        ->and($nested->keys()->all())->toBe([7])
        ->and($nested->first())->toBeInstanceOf($attachments ? Attachment::class : Comment::class)
        ->and($nested->first()->id)->toBe($attachments ? 4 : 3);

    if ($attachments) {
        expect($nested->first()->ticket_id)->toBe(2)
            ->and($nested->first()->comment_id)->toBe(3);
    }
})->with([false, true])->with(['array', 'collection', 'iterator', 'generator', 'lazy collection']);

it('retains existing comment DTOs in traversables', function () {
    $comment = Comment::fake();
    $ticket = Ticket::fromJson(DtoFixtures::ticket(['comments' => new ArrayIterator([$comment])]));

    expect($ticket->comments->first())->toBe($comment);
});

it('treats null nested collections as empty', function () {
    expect(Ticket::fromJson(DtoFixtures::ticket(['comments' => null]))->comments)->toBeEmpty()
        ->and(Comment::fromJson(DtoFixtures::comment(['attachments' => null]))->attachments)->toBeEmpty();
});

it('rejects malformed nested collection containers', function (bool $attachments, mixed $input) {
    expect(fn () => $attachments
        ? Comment::fromJson(DtoFixtures::comment(['attachments' => $input]))
        : Ticket::fromJson(DtoFixtures::ticket(['comments' => $input])))
        ->toThrow(TypeError::class);
})->with([false, true])->with([false, true, 0, 5, 1.5, '', 'malformed', new stdClass]);
