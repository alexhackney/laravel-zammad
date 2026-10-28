<?php

declare(strict_types=1);

use AlexHackney\Zammad\Data\Attachment;
use AlexHackney\Zammad\Data\Comment;
use AlexHackney\Zammad\Data\ObjectAttribute;
use AlexHackney\Zammad\Data\Ticket;
use AlexHackney\Zammad\Data\User;
use AlexHackney\Zammad\Tests\Unit\Data\DtoFixtures;
use Illuminate\Support\Str;

require_once __DIR__.'/DtoFixtures.php';

dataset('DTO integer fields', [
    'attachment id' => [Attachment::class, 'id', 'id'],
    'attachment size' => [Attachment::class, 'size', 'size'],
    'comment id' => [Comment::class, 'id', 'id'],
    'comment type' => [Comment::class, 'type_id', 'type_id'],
    'comment ticket' => [Comment::class, 'ticket_id', 'ticket_id'],
    'comment sender' => [Comment::class, 'sender_id', 'sender_id'],
    'comment creator' => [Comment::class, 'created_by_id', 'created_by_id'],
    'comment updater' => [Comment::class, 'updated_by_id', 'updated_by_id'],
    'comment origin' => [Comment::class, 'origin_by_id', 'origin_by_id'],
    'object attribute id' => [ObjectAttribute::class, 'id', 'id'],
    'object attribute lookup' => [ObjectAttribute::class, 'object_lookup_id', 'object_lookup_id'],
    'object attribute position' => [ObjectAttribute::class, 'position', 'position'],
    'ticket id' => [Ticket::class, 'id', 'id'],
    'ticket number' => [Ticket::class, 'number', 'number'],
    'ticket customer' => [Ticket::class, 'customer_id', 'customer_id'],
    'ticket group' => [Ticket::class, 'group_id', 'group_id'],
    'ticket state' => [Ticket::class, 'state_id', 'state_id'],
    'ticket comment count' => [Ticket::class, 'article_count', 'comments_count'],
    'user id' => [User::class, 'id', 'id'],
]);

dataset('DTO string fields', [
    'attachment filename' => [Attachment::class, 'filename', 'name'],
    'comment sender' => [Comment::class, 'sender', 'sender'],
    'comment subject' => [Comment::class, 'subject', 'subject'],
    'comment body' => [Comment::class, 'body', 'body'],
    'comment content type' => [Comment::class, 'content_type', 'content_type'],
    'comment from' => [Comment::class, 'from', 'from'],
    'comment to' => [Comment::class, 'to', 'to'],
    'object attribute name' => [ObjectAttribute::class, 'name', 'name'],
    'object attribute display' => [ObjectAttribute::class, 'display', 'display'],
    'object attribute data type' => [ObjectAttribute::class, 'data_type', 'data_type'],
    'ticket title' => [Ticket::class, 'title', 'subject'],
    'user first name' => [User::class, 'firstname', 'first_name'],
    'user last name' => [User::class, 'lastname', 'last_name'],
    'user login' => [User::class, 'login', 'login'],
    'user email' => [User::class, 'email', 'email'],
]);

it('restores weak scalar conversions for each integer field', function (string $dto, string $field, string $property) {
    foreach ([
        ['23001', 23001], [' 23 ', 23], ['2.3e2', 230], ['23.75', 23],
        [23.75, 23], [-23.75, -23], [true, 1], [false, 0], [0, 0],
        [(string) PHP_INT_MAX, PHP_INT_MAX], [(string) PHP_INT_MIN, PHP_INT_MIN],
    ] as [$input, $expected]) {
        expect(DtoFixtures::hydrate($dto, [$field => $input])->$property)->toBe($expected);
    }
})->with('DTO integer fields');

it('rejects invalid integer values instead of silently casting them', function (string $dto, string $field) {
    foreach (['not-numeric', '23suffix', '', [], new stdClass, '1e100', INF, NAN] as $input) {
        expect(fn () => DtoFixtures::hydrate($dto, [$field => $input]))->toThrow(TypeError::class);
    }
})->with('DTO integer fields');

it('restores weak scalar conversions for each string field', function (string $dto, string $field, string $property) {
    foreach ([[5, '5'], [5.25, '5.25'], [true, '1'], [false, ''], ['unchanged', 'unchanged']] as [$input, $expected]) {
        expect(DtoFixtures::hydrate($dto, [$field => $input])->$property)->toBe($expected);
    }
})->with('DTO string fields');

it('accepts Stringable values for each string field', function (string $dto, string $field, string $property) {
    $input = new class implements Stringable
    {
        public function __toString(): string
        {
            return '5';
        }
    };

    expect(DtoFixtures::hydrate($dto, [$field => $input])->$property)->toBe('5');
})->with('DTO string fields');

it('rejects arrays and non-Stringable objects for each string field', function (string $dto, string $field) {
    foreach ([[], new stdClass] as $input) {
        expect(fn () => DtoFixtures::hydrate($dto, [$field => $input]))->toThrow(TypeError::class);
    }
})->with('DTO string fields');

it('normalizes either attachment media type preference', function (string $preference) {
    foreach ([[5, '5'], [5.25, '5.25'], [true, '1'], [false, ''], ['text/plain', 'text/plain'], [Str::of('text/plain'), 'text/plain']] as [$input, $expected]) {
        $attachment = Attachment::fromJson(DtoFixtures::attachment(['preferences' => [$preference => $input]]), 2, 3);

        expect($attachment->type)->toBe($expected);
    }

    foreach ([null, [], new stdClass] as $input) {
        expect(fn () => Attachment::fromJson(DtoFixtures::attachment(['preferences' => [
            'Content-Type' => $input,
            'Mime-Type' => $input,
        ]]), 2, 3))->toThrow(TypeError::class);
    }
})->with(['Content-Type', 'Mime-Type']);

it('uses scalar truthiness for the comment internal flag', function (mixed $input, bool $expected) {
    expect(Comment::fromJson(DtoFixtures::comment(['internal' => $input]))->internal)->toBe($expected);
})->with([
    [true, true], [false, false], [1, true], [0, false], [0.5, true], [0.0, false],
    ['1', true], ['0', false], ['false', true], ['', false],
]);

it('rejects arrays objects and null for the internal flag', function () {
    foreach ([[], new stdClass, null] as $input) {
        expect(fn () => Comment::fromJson(DtoFixtures::comment(['internal' => $input])))->toThrow(TypeError::class);
    }
});

it('preserves null for nullable comment fields', function () {
    $comment = Comment::fromJson(DtoFixtures::comment(['subject' => null, 'to' => null, 'origin_by_id' => null]));

    expect($comment->subject)->toBeNull()
        ->and($comment->to)->toBeNull()
        ->and($comment->origin_by_id)->toBeNull();
});

it('preserves nullable object attribute fields and a missing lookup id', function () {
    $data = DtoFixtures::objectAttribute(['object_lookup_id' => null, 'data_option_new' => null]);
    $attribute = ObjectAttribute::fromJson($data);

    expect($attribute->object_lookup_id)->toBeNull()
        ->and($attribute->data_option_new)->toBeNull();

    unset($data['object_lookup_id']);

    expect(ObjectAttribute::fromJson($data)->object_lookup_id)->toBeNull();
});

it('preserves null expanded payloads and retains raw expanded data', function () {
    $ticketData = DtoFixtures::ticket(['number' => '23001']);
    $userData = DtoFixtures::user(['firstname' => 5]);

    expect(Ticket::fromJson($ticketData)->expanded)->toBeNull()
        ->and(User::fromJson($userData)->expanded)->toBeNull()
        ->and(Ticket::fromJson($ticketData, true)->expanded)->toBe($ticketData)
        ->and(User::fromJson($userData, true)->expanded)->toBe($userData);
});

it('defaults absent and null article counts to zero', function () {
    $data = DtoFixtures::ticket(['article_count' => null]);

    expect(Ticket::fromJson($data)->comments_count)->toBe(0);

    unset($data['article_count']);

    expect(Ticket::fromJson($data)->comments_count)->toBe(0);
});

it('normalizes comment ids before hydrating nested attachments', function () {
    $comment = Comment::fromJson(DtoFixtures::comment([
        'id' => '3',
        'ticket_id' => '2',
        'attachments' => [DtoFixtures::attachment(['id' => '4', 'size' => '37055'])],
    ]));

    expect($comment->attachments->first()->ticket_id)->toBe(2)
        ->and($comment->attachments->first()->comment_id)->toBe(3)
        ->and($comment->attachments->first()->id)->toBe(4)
        ->and($comment->attachments->first()->size)->toBe(37055);
});

it('keeps every fake factory compatible with strict constructors', function () {
    expect(Attachment::fake()->size)->toBeInt()
        ->and(Comment::fake()->attachments->first())->toBeInstanceOf(Attachment::class)
        ->and(ObjectAttribute::fake()->name)->toBeString()
        ->and(Ticket::fake()->comments->first())->toBeInstanceOf(Comment::class)
        ->and(User::fake()->first_name)->toBeString();
});
