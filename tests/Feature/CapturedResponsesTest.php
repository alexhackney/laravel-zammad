<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Tests\Feature;

use AlexHackney\Zammad\Data\Comment;
use AlexHackney\Zammad\Data\ObjectAttribute;
use AlexHackney\Zammad\Data\Ticket;
use AlexHackney\Zammad\Data\User;
use AlexHackney\Zammad\Requests\AccessTokens\AllAccessTokensRequest;
use AlexHackney\Zammad\Requests\Comments\GetCommentByTicketRequest;
use AlexHackney\Zammad\Requests\Comments\GetCommentRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\AllObjectAttributesRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\GetObjectAttributeRequest;
use AlexHackney\Zammad\Requests\Tickets\AllTicketsRequest;
use AlexHackney\Zammad\Requests\Tickets\CreateTicketRequest;
use AlexHackney\Zammad\Requests\Tickets\DestroyTicketRequest;
use AlexHackney\Zammad\Requests\Tickets\GetTicketRequest;
use AlexHackney\Zammad\Requests\Tickets\SearchTicketRequest;
use AlexHackney\Zammad\Requests\Users\AllUsersRequest;
use AlexHackney\Zammad\Requests\Users\GetUserRequest;
use AlexHackney\Zammad\Requests\Users\SearchUserRequest;
use AlexHackney\Zammad\Zammad;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/*
 * Responses captured from a real Zammad instance (tests/Fixtures/responses/*.json, personal data replaced by
 * the capture script, structure, types and nulls unchanged). These tests exist so the DTOs and resources are
 * checked against real payloads and not only against shapes inferred from the DTO fields.
 */

/**
 * @return array{status: int, body: mixed}
 */
function capture(string $name): array
{
    $file = __DIR__.'/../Fixtures/responses/'.$name.'.json';
    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    return ['status' => (int) $data['status'], 'body' => $data['body']];
}

function replay(string $request, string $name): MockResponse
{
    $c = capture($name);

    return MockResponse::make($c['body'], $c['status']);
}

beforeEach(function () {
    config(['zammad.http_retry_delay' => 0]);
});

afterEach(function () {
    MockClient::destroyGlobal();
});

it('lists and shows real tickets', function () {
    MockClient::global([
        AllTicketsRequest::class => replay(AllTicketsRequest::class, 'tickets_list'),
        GetTicketRequest::class => replay(GetTicketRequest::class, 'ticket_show'),
    ]);
    $expected = count(capture('tickets_list')['body']);

    $tickets = (new Zammad)->ticket()->list();

    expect($tickets)->toBeInstanceOf(Collection::class)->toHaveCount($expected);
    expect($tickets->first())->toBeInstanceOf(Ticket::class);
    expect($tickets->first()->number)->toBeInt();

    $ticket = (new Zammad)->ticket()->show(1);
    expect($ticket->id)->toBe(1)->and($ticket->number)->toBeInt();
});

it('keeps the raw payload of an expanded real ticket', function () {
    MockClient::global([GetTicketRequest::class => replay(GetTicketRequest::class, 'ticket_show_expanded')]);

    $ticket = (new Zammad)->ticket()->expand()->show(1);

    expect($ticket->expanded)->toBeArray()->not->toBeEmpty();
});

it('searches tickets when Zammad answers with a plain list', function () {
    MockClient::global([SearchTicketRequest::class => replay(SearchTicketRequest::class, 'tickets_search')]);
    $ids = array_column(capture('tickets_search')['body'], 'id');

    $tickets = (new Zammad)->ticket()->limit(3)->search('a');

    expect($tickets)->toHaveCount(count($ids));
    expect($tickets->pluck('id')->all())->toBe($ids);
});

it('searches tickets with paging when Zammad answers with a plain list', function () {
    MockClient::global([SearchTicketRequest::class => replay(SearchTicketRequest::class, 'tickets_search_paged')]);
    $ids = array_column(capture('tickets_search_paged')['body'], 'id');

    $tickets = (new Zammad)->ticket()->limit(2)->paginate(2, 2)->search('a');

    expect($tickets->pluck('id')->all())->toBe($ids);
});

it('still searches tickets when Zammad answers with the older assets format', function () {
    $ticket = capture('tickets_search')['body'];
    $first = $ticket[0];
    $second = $ticket[1];
    // derived from the captured ticket objects: the older response lists the ids in `tickets`
    MockClient::global([SearchTicketRequest::class => MockResponse::make([
        'tickets' => [$second['id'], $first['id']],
        'tickets_count' => 2,
        'assets' => ['Ticket' => [(string) $first['id'] => $first, (string) $second['id'] => $second]],
    ], 200)]);

    $tickets = (new Zammad)->ticket()->search('a');

    expect($tickets->pluck('id')->all())->toBe([$second['id'], $first['id']]);
});

it('shows a real ticket with its public comments', function () {
    MockClient::global([
        GetTicketRequest::class => replay(GetTicketRequest::class, 'ticket_show'),
        GetCommentByTicketRequest::class => replay(GetCommentByTicketRequest::class, 'ticket_articles_by_ticket'),
    ]);

    $ticket = (new Zammad)->ticket()->showWithComments(1);

    expect($ticket->comments)->toHaveCount(1);
    expect($ticket->comments->first())->toBeInstanceOf(Comment::class);
    expect($ticket->comments->first()->internal)->toBeFalse();
});

it('shows a real article', function () {
    MockClient::global([GetCommentRequest::class => replay(GetCommentRequest::class, 'ticket_article_show')]);

    $comment = (new Zammad)->comment()->show(1);

    expect($comment)->toBeInstanceOf(Comment::class);
    expect($comment->id)->toBeInt();
    expect($comment->body_filtered)->toBeString();
});

it('reads real users', function () {
    MockClient::global([
        GetUserRequest::class => replay(GetUserRequest::class, 'user_me'),
        AllUsersRequest::class => replay(AllUsersRequest::class, 'users_list'),
    ]);

    $me = (new Zammad)->user()->me();
    $users = (new Zammad)->user()->list();

    expect($me)->toBeInstanceOf(User::class);
    expect($users)->toHaveCount(count(capture('users_list')['body']));
    expect($users->first())->toBeInstanceOf(User::class);
});

it('finds a real user by email without creating one', function () {
    $email = capture('users_search')['body'][0]['email'];
    $mock = MockClient::global([SearchUserRequest::class => replay(SearchUserRequest::class, 'users_search')]);

    $user = (new Zammad)->user()->searchOrCreateByEmail($email);

    expect($user->email)->toBe($email);
    $mock->assertSentCount(1);
});

it('reads real object attributes', function () {
    MockClient::global([
        AllObjectAttributesRequest::class => replay(AllObjectAttributesRequest::class, 'object_attributes_list'),
        GetObjectAttributeRequest::class => replay(GetObjectAttributeRequest::class, 'object_attribute_show'),
    ]);

    $all = (new Zammad)->object()->list();
    $one = (new Zammad)->object()->show(58);

    expect($all)->toHaveCount(count(capture('object_attributes_list')['body']));
    expect($all->first())->toBeInstanceOf(ObjectAttribute::class);
    expect($one)->toBeInstanceOf(ObjectAttribute::class);
});

it('lists real access tokens', function () {
    MockClient::global([AllAccessTokensRequest::class => replay(AllAccessTokensRequest::class, 'user_access_tokens_list')]);

    $result = (new Zammad)->userAccessToken()->list();

    expect($result)->toHaveKeys(['tokens', 'permissions']);
});

it('throws the real 404 once without retrying', function () {
    $mock = MockClient::global([GetTicketRequest::class => replay(GetTicketRequest::class, 'error_404_ticket')]);

    try {
        (new Zammad)->ticket()->show(999999999);
        $this->fail('expected a RequestException');
    } catch (RequestException $e) {
        expect($e->getResponse()->status())->toBe(404);
        expect($e->getResponse()->json())->toHaveKeys(['error', 'error_human']);
    }
    $mock->assertSentCount(1);
});

it('throws the real 401 and 422 error responses', function (int $status, string $name, string $request) {
    $mock = MockClient::global([$request => replay($request, $name)]);

    try {
        (new Zammad)->ticket()->create([]);
        $this->fail('expected a RequestException');
    } catch (RequestException $e) {
        expect($e->getResponse()->status())->toBe($status);
    }
    $mock->assertSentCount(1);
})->with([
    '422 validation' => [422, 'error_validation_ticket', CreateTicketRequest::class],
    '401 bad token' => [401, 'error_401_bad_token', CreateTicketRequest::class],
]);

it('ignores a real 422 error body on delete when the configured message matches its error field', function () {
    $error = (string) capture('error_validation_ticket')['body']['error'];
    config(['zammad.object_reference_error_ignore' => true, 'zammad.object_reference_error' => $error]);
    MockClient::global([DestroyTicketRequest::class => replay(DestroyTicketRequest::class, 'error_validation_ticket')]);

    (new Zammad)->ticket()->delete(1);

    expect(true)->toBeTrue();
});

it('does not ignore a real 422 error body on delete when the message differs', function () {
    config(['zammad.object_reference_error_ignore' => true, 'zammad.object_reference_error' => "Can't delete, object has references."]);
    MockClient::global([DestroyTicketRequest::class => replay(DestroyTicketRequest::class, 'error_validation_ticket')]);

    (new Zammad)->ticket()->delete(1);
})->throws(RequestException::class);

it('shows a real ticket whose articles carry attachments', function () {
    MockClient::global([
        GetTicketRequest::class => replay(GetTicketRequest::class, 'ticket_show_with_attachments'),
        GetCommentByTicketRequest::class => replay(GetCommentByTicketRequest::class, 'ticket_articles_with_attachments'),
    ]);
    $ticketId = (int) capture('ticket_show_with_attachments')['body']['id'];

    $ticket = (new Zammad)->ticket()->showWithComments($ticketId);

    expect($ticket->comments)->toHaveCount(5);
    expect($ticket->comments->every(fn (Comment $c): bool => $c->internal === false))->toBeTrue();

    $withFiles = $ticket->comments->first(fn (Comment $c): bool => $c->attachments->isNotEmpty());
    expect($withFiles)->toBeInstanceOf(Comment::class);
    expect($withFiles->attachments)->toHaveCount(2);

    foreach ($withFiles->attachments as $attachment) {
        expect($attachment->size)->toBeInt()
            ->and($attachment->name)->toBeString()->not->toBe('')
            ->and($attachment->type)->toBeString()->not->toBe('')
            ->and($attachment->ticket_id)->toBe($ticketId)
            ->and($attachment->comment_id)->toBe($withFiles->id);
    }
});

it('reads the attachment type from Mime-Type when the real preferences have no Content-Type', function () {
    $articles = capture('ticket_articles_with_attachments')['body'];
    $withFiles = array_values(array_filter($articles, fn (array $a): bool => ($a['attachments'] ?? []) !== []))[0];
    $first = $withFiles['attachments'][0];

    expect($first['preferences'])->not->toHaveKey('Content-Type')->toHaveKey('Mime-Type');

    $comment = Comment::fromJson($withFiles);

    expect($comment->attachments[0]->type)->toBe($first['preferences']['Mime-Type']);
});

it('builds the download url of a real attachment', function () {
    $articles = capture('ticket_articles_with_attachments')['body'];
    $withFiles = array_values(array_filter($articles, fn (array $a): bool => ($a['attachments'] ?? []) !== []))[0];
    $comment = Comment::fromJson($withFiles);
    $attachment = $comment->attachments[0];

    expect($attachment->url())->toBe(sprintf(
        'https://zammad.test/api/v1/ticket_attachment/%d/%d/%d',
        $comment->ticket_id,
        $comment->id,
        $attachment->id,
    ));
});

it('keeps the raw payload of a real expanded ticket with many relations', function () {
    MockClient::global([GetTicketRequest::class => replay(GetTicketRequest::class, 'ticket_show_expanded_with_attachments')]);

    $ticket = (new Zammad)->ticket()->expand()->show(1789);

    expect($ticket->expanded)->toBeArray()->toHaveKeys(['article_ids', 'group', 'state']);
});

it('shows a real customer without an organization', function () {
    MockClient::global([GetUserRequest::class => replay(GetUserRequest::class, 'user_show_customer')]);

    $user = (new Zammad)->user()->show(1304);

    expect($user)->toBeInstanceOf(User::class);
    expect(capture('user_show_customer')['body']['organization_id'])->toBeNull();
});
