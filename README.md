# Laravel Zammad

[![Latest Version on Packagist](https://img.shields.io/packagist/v/alexhackney/laravel-zammad.svg?style=flat-square)](https://packagist.org/packages/alexhackney/laravel-zammad)
[![Tests](https://img.shields.io/github/actions/workflow/status/alexhackney/laravel-zammad/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/alexhackney/laravel-zammad/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/alexhackney/laravel-zammad.svg?style=flat-square)](LICENSE.md)

A Laravel client for the [Zammad](https://zammad.org) helpdesk REST API, built on
[Saloon](https://docs.saloon.dev) 4. It covers users, tickets, comments (ticket articles), attachments,
object attributes and user access tokens, and returns typed data objects (DTOs) instead of raw arrays.

This is a maintained fork of `codebar-ag/laravel-zammad`.

## Coming from codebar-ag/laravel-zammad?

The package, the namespace (`AlexHackney\Zammad`), one config key and several behaviours changed in this
release (retries, redirects, internal notes, HTML sanitising). Read [UPGRADING.md](UPGRADING.md) before you
switch (it covers the `bin/migrate-from-codebar` helper), and [CHANGELOG.md](CHANGELOG.md) for the full list.

## Requirements

- PHP 8.3 or higher
- Laravel 12 or 13
- A Zammad instance with "HTTP Token Authentication" enabled

## Installation

```bash
composer require alexhackney/laravel-zammad
```

Publish the config file (optional, the defaults work with just the two env keys below):

```bash
php artisan vendor:publish --tag="zammad-config"
```

Add your Zammad URL and token to `.env`:

```dotenv
ZAMMAD_URL=https://helpdesk.example.com
ZAMMAD_TOKEN=your-access-token
```

Use the base URL of your instance (scheme and host, no `/api/v1`). Always use `https://`.

To get a token: in Zammad open your profile (avatar, bottom left), go to **Token Access**, click **Create**,
name it and choose the permissions it needs. Zammad shows the token only once. An admin must first enable
**Settings > System > API > API Token Access**.

Check that it works:

```bash
php artisan zammad:ping
```

The command calls `users/me`, prints the login of the connected user, and exits with `0` on success or `1`
on any failure, including connection errors and non-JSON responses. It never prints the token. With `--json` it
always prints exactly one JSON object.

## Usage

Use the `Zammad` facade. Most calls return DTOs from `AlexHackney\Zammad\Data` (or a `Collection` of them). The exceptions: attachment
`download()` returns the raw file content as a `string`, the access-token methods `list()`, `create()` and `delete()`
return arrays (the decoded JSON body, empty for an empty response), and the resource `delete()` methods and
`executeMigrations()` return `void`. Calls
throw a `Saloon\Exceptions\Request\RequestException` when Zammad answers with an error.

```php
use AlexHackney\Zammad\Facades\Zammad;
```

| Facade method | Resource |
|---|---|
| `Zammad::user()` | users |
| `Zammad::ticket()` | tickets |
| `Zammad::comment()` | comments (ticket articles) |
| `Zammad::attachment()` | attachments |
| `Zammad::object()` | object attributes |
| `Zammad::userAccessToken()` | access tokens of the current user |

### Users

```php
$me = Zammad::user()->me();                       // User
$user = Zammad::user()->show(5);                  // User, id must be 1 or higher
$users = Zammad::user()->list();                  // Collection<int, User>

$user = Zammad::user()->create([
    'firstname' => 'Ada',
    'lastname' => 'Lovelace',
    'email' => 'ada@example.com',
]);

$user = Zammad::user()->update(5, ['note' => 'VIP']);   // id: int|string, positive integer
Zammad::user()->delete(5);                               // void

// One result (User or null) with the default limit of 1
$user = Zammad::user()->search('email:ada@example.com');

// Several results (Collection<int, User>) when the limit is above 1
$users = Zammad::user()->limit(10)->search('lovelace');

// Find by exact email (case-insensitive) or create. The second argument is
// used only when the user is created.
$user = Zammad::user()->searchOrCreateByEmail('ada@example.com', [
    'firstname' => 'Ada',
    'lastname' => 'Lovelace',
]);
```

`show(0)` is rejected; use `me()` for the current user. `searchOrCreateByEmail()` rejects emails that are
empty or contain whitespace, `"`, `\`, `(`, `)`, `*`, `?` or `:`.

### Tickets

```php
$tickets = Zammad::ticket()->list();                      // Collection<int, Ticket>
$ticket = Zammad::ticket()->show(12);                     // Ticket
$ticket = Zammad::ticket()->showWithComments(12);         // Ticket with ->comments filled, no internal notes
$tickets = Zammad::ticket()->limit(10)->search('state:open');   // current and older Zammad response formats

$ticket = Zammad::ticket()->create([
    'title' => 'Printer is on fire',
    'group' => 'Users',
    'customer' => 'ada@example.com',
    'article' => [
        'subject' => 'Printer is on fire',
        'body' => 'Please help.',
        'type' => 'note',
        'internal' => false,
    ],
]);

Zammad::ticket()->delete(12);                             // void
```

Deleting a ticket is `Zammad::ticket()->delete($id)`. Deleting needs the `admin` permission in Zammad.

### Comments

A comment is a Zammad "article".

```php
use AlexHackney\Zammad\Facades\Zammad;

$comment = Zammad::comment()->show(31);                   // Comment
$comments = Zammad::comment()->showByTicket(12);          // Collection<int, Comment>, no internal notes

$comments = Zammad::comment()->showByTicket(12, includeInternal: true);

$comment = Zammad::comment()->create([
    'ticket_id' => 12,
    'body' => 'Thanks, we are on it.',
    'type' => 'note',
    'internal' => false,
]);

Zammad::comment()->delete(31);                            // void
```

### Attachments

Attachments are listed on `Comment::$attachments` (a `Collection<int, Attachment>`). `download()` returns the
file content as a string.

```php
$comment = Zammad::comment()->show(31);

foreach ($comment->attachments as $attachment) {
    $content = Zammad::attachment()->download(
        ticketId: $attachment->ticket_id,
        commentId: $attachment->comment_id,
        attachmentId: $attachment->id,
    );

    Storage::put($attachment->name, $content);
}

$url = $attachment->url();   // https://helpdesk.example.com/api/v1/ticket_attachment/12/31/7
```

`Attachment::url()` needs `ZAMMAD_URL` to be an absolute `http(s)` URL and throws otherwise. The URL needs the
Authorization header to be fetched; it is not a public link.

### Object attributes

```php
$attributes = Zammad::object()->list();                   // Collection<int, ObjectAttribute>
$attribute = Zammad::object()->show(3);                   // ObjectAttribute

$attribute = Zammad::object()->create([
    'name' => 'priority_note',
    'object' => 'Ticket',
    'display' => 'Priority note',
    'active' => true,
    'data_type' => 'input',
    'data_option' => ['type' => 'text', 'maxlength' => 120],
]);

$attribute = Zammad::object()->update(3, ['display' => 'Priority']);
Zammad::object()->delete(3);                              // void

// Zammad applies attribute changes only after the migrations are executed
Zammad::object()->executeMigrations();                    // void
```

### Access tokens

These act on the user that owns the configured token. `list()` and `create()` return the decoded JSON as an `array`; `delete()` returns `void`.

```php
$tokens = Zammad::userAccessToken()->list();

$token = Zammad::userAccessToken()->create([
    'name' => 'ci-token',
    'permission' => ['ticket.agent'],
]);

Zammad::userAccessToken()->delete(4);                     // id: int|string, positive integer
```

## Expand, limit and pagination

`UserResource` and `TicketResource` have fluent modifiers. They live on the resource instance, so chain them in
the same call.

```php
// expand(): ask Zammad for expanded data. The raw response is kept in $ticket->expanded.
$ticket = Zammad::ticket()->expand()->show(12);
$ticket->expanded;                                        // array

// limit(): used by search(). The argument defaults to 1.
$tickets = Zammad::ticket()->limit(25)->search('state:open');

// page(), perPage(), paginate(): used by list() (and by ticket search())
$users = Zammad::user()->perPage(50)->page(2)->list();
$tickets = Zammad::ticket()->paginate(page: 2, perPage: 50)->list();
```

`expand()` applies to `me()`, `show()`, `create()` and `update()` on users and to `show()`, `showWithComments()`
and `create()` on tickets. Without `expand()` the `expanded` property is `null`.

## Custom ticket attributes

Declare your own Zammad ticket fields in `config/zammad.php` to have them cast and set as dynamic properties on
`Ticket`. Supported types: `string`, `integer`, `float`, `boolean`, `datetime`.

```php
'ticket' => [
    'note' => 'string',
    'priority_score' => 'integer',
    'needs_callback' => 'boolean',
    'due_on' => 'datetime',     // Carbon
],
```

```php
$ticket = Zammad::ticket()->show(12);

$ticket->note;
$ticket->needs_callback;        // bool; "false", "0", "no" and "off" are false
```

Only attributes present in the response are set. An attribute that has the name of a declared `Ticket` property
(for example `id` or `subject`) never overwrites that property.

## Data objects

All DTOs are in the `AlexHackney\Zammad\Data` namespace. Values from the Zammad JSON are normalised to the
declared types, for example the ticket `number` (a numeric string in the API) is an `int`.

### `User`

`id`, `first_name`, `last_name`, `login`, `email`, `last_login_at`, `updated_at`, `created_at` (Carbon),
`expanded` (`?array`).

### `Ticket`

`id`, `number`, `customer_id`, `group_id`, `state_id`, `subject`, `comments_count`, `updated_at`, `created_at`
(Carbon), `comments` (`Collection<int, Comment>`), `expanded` (`?array`), plus your custom attributes.

```php
$ticket->state();        // 'new', 'open', 'pending_reminder', 'closed', 'merged', 'removed', 'pending_close' or 'unknown'
$ticket->isOpen();       // the four state helpers read the ids in config('zammad.ticket_states')
$ticket->isClosed();
$ticket->isActive();
$ticket->isInactive();
```

### `Comment`

`id`, `type_id`, `ticket_id`, `sender_id`, `sender`, `subject` (`?string`), `body`, `body_filtered`,
`content_type`, `from`, `to` (`?string`), `internal` (bool), `created_by_id`, `updated_by_id`,
`origin_by_id` (`?int`), `attachments` (`Collection<int, Attachment>`), `updated_at`, `created_at` (Carbon).

```php
$comment->body;            // the original body from Zammad, unchanged
$comment->body_filtered;   // sanitised, safe-to-render version (see Security notes)
$comment->fromName();      // from a header such as: "Ada Lovelace" <ada@example.com>
$comment->fromEmail();
```

`body_filtered` is derived from `body`. Depending on the config it also drops images, flattens tables and cuts
the text at the signature marker.

### `Attachment`

`id`, `ticket_id`, `comment_id`, `size`, `name`, `type`; `url()` as shown above.

### `ObjectAttribute`

`id`, `name`, `object_lookup_id` (`?int`), `display`, `data_type`, `position`, `data_option` (array),
`data_option_new` (`?array`).

Each DTO also has a static `fake()` factory for your own tests, for example `Ticket::fake()`.

## Security notes

- **`body_filtered` is sanitised.** It is produced with `symfony/html-sanitizer`: scripts, event handlers,
  unsafe URLs, forms and other raw-text elements are removed. Input longer than 200000 bytes is truncated in
  `body_filtered` (not in `body`), and very deeply nested markup falls back to escaped text. Plain-text comments
  are HTML-escaped. Render `body_filtered`, never `body`, as HTML. `body_filtered` is intended for display; treat
  it as untrusted content that was made safe, not as trusted content.
- **Internal notes are excluded by default.** `Zammad::comment()->showByTicket()` and
  `Zammad::ticket()->showWithComments()` drop internal articles. Pass `includeInternal: true` to get them, and
  only do that for agent-facing views. `Zammad::comment()->show()` returns whatever comment id you ask for, internal or not.
- **Never log `ZammadResponseLog`.** The event carries the whole Saloon `Response`, and its request holds the
  `Authorization: Bearer ...` header. Do not log, serialise or report the event or the response. Log only
  `$event->response->status()` and the URL, for example.
- **Redirects are errors.** Redirects are not followed. Any 3xx response throws a `RequestException`, so a
  write is never replayed or reported as a success because of a redirect.
- **Retries are conservative.** See [Retries and timeouts](#retries-and-timeouts). A failed POST is not
  re-sent after the connection opened.
- **Use `https://`.** With a plain `http://` URL the token is sent unencrypted. The package logs a warning
  once per process in that case.
- **Payload arrays are passed through.** `create()`, `update()` and similar methods send your array to Zammad as
  is. Never pass unvalidated request input (for example `$request->all()`): a caller could set fields such as
  `role_ids`, `group`, `owner_id` or `permission`. Build the array from an explicit list of keys.
- **Give the token the least permissions it needs**, and keep it out of version control.

## Configuration

Publish the file with `php artisan vendor:publish --tag="zammad-config"`.

| Key | Env var | Default | Description |
|---|---|---|---|
| `url` | `ZAMMAD_URL` | `null` | Base URL of your Zammad instance (scheme and host). Required. |
| `token` | `ZAMMAD_TOKEN` | `null` | Access token. Required. |
| `http_retry_maximum` | `ZAMMAD_HTTP_RETRY_MAXIMUM` | `3` | Total number of attempts per request. |
| `http_retry_delay` | `ZAMMAD_HTTP_RETRY_DELAY` | `1500` | Delay between attempts, in milliseconds. |
| `connect_timeout` | `ZAMMAD_CONNECT_TIMEOUT` | `10` | Seconds allowed to open the connection. |
| `request_timeout` | `ZAMMAD_REQUEST_TIMEOUT` | `30` | Seconds allowed for the whole request. |
| `object_reference_error_ignore` | `ZAMMAD_OBJECT_REFERENCE_ERROR_IGNORE` | `false` | Return the 422 "object has references" response of a delete without throwing. |
| `object_reference_error` | `ZAMMAD_OBJECT_REFERENCE_ERROR` | `Can't delete, object has references.` | Message matched against the response (after decoding HTML entities). A JSON body is matched only by its string `error` field; a body that is not JSON at all is matched as the whole raw body; JSON that cannot be parsed because of resource limits throws (fails closed). |
| `filter_images` | `ZAMMAD_FILTER_IMAGES` | `true` | Remove `<img>` from `body_filtered`. |
| `filter_tables` | `ZAMMAD_FILTER_TABLES` | `true` | Unwrap tables in `body_filtered` (the text is kept, the table tags are removed). |
| `filter_signature_marker` | `ZAMMAD_FILTER_SIGNATURE_MARKER` | `true` | Cut HTML bodies at the signature marker. |
| `filter_signature_marker_value` | `ZAMMAD_FILTER_SIGNATURE_MARKER_VALUE` | `<span class="js-signatureMarker"></span>` | The signature marker to cut at. |
| `filter_data_signature` | `ZAMMAD_FILTER_DATA_SIGNATURE` | `true` | Cut HTML bodies at the data-signature element. |
| `filter_data_signature_value` | `ZAMMAD_FILTER_DATA_SIGNATURE_VALUE` | `<div data-signature="true" data-signature-id="1">` | The data-signature markup to cut at. |
| `ticket` | none | `[]` | Custom ticket attributes and their cast type. |
| `ticket_states` | none | `open: [1, 2, 3, 7]`, `closed: [4]`, `active: [1, 2, 3, 4, 7]`, `inactive: [5, 6]` | State ids behind `Ticket::isOpen()` and the related helpers. |

The `object_reference_error_ignore` option only applies to delete calls. Deleting data cannot be undone and
Zammad may remove references, including tickets; see the
[Zammad docs](https://docs.zammad.org/en/latest/api/user.html#delete) and consider Data Privacy in the UI.

## Retries and timeouts

A request is attempted up to `http_retry_maximum` times, waiting `http_retry_delay` milliseconds between
attempts (no backoff).

| Request | Retried on |
|---|---|
| GET, HEAD, PUT, DELETE | connection failures, HTTP 429 and HTTP 5xx |
| POST | only when the host could not be resolved or connected to (the request never left) |
| any | HTTP 4xx other than 429 is never retried |

`connect_timeout` and `request_timeout` are in seconds. When all attempts fail, the last exception is thrown.

## Events

Each operation is announced once with `AlexHackney\Zammad\Events\ZammadResponseLog`: it carries the final
response (after Saloon's retries) or the final failed response. Intermediate retried responses are not announced.
Its public `$response` property is the Saloon `Response`.

```php
use AlexHackney\Zammad\Events\ZammadResponseLog;
use Illuminate\Support\Facades\Event;

Event::listen(function (ZammadResponseLog $event) {
    // Safe: status and endpoint only. Never log $event->response itself,
    // its request carries the Authorization header.
    logger()->info('Zammad call', [
        'status' => $event->response->status(),
        'path' => $event->response->getPendingRequest()->getUri()->getPath(),
    ]);
});
```

## Testing

`AlexHackney\Zammad\Testing\FakeZammad` installs a global Saloon `MockClient`, so no request reaches Zammad. It
also calls `Saloon\Config::preventStrayRequests()`: any request without a matching mock throws.

```php
use AlexHackney\Zammad\Facades\Zammad;
use AlexHackney\Zammad\Testing\FakeZammad;
use AlexHackney\Zammad\Requests\Tickets\GetTicketRequest;
use Saloon\Http\Faking\MockResponse;

$mock = FakeZammad::fake([
    GetTicketRequest::class => MockResponse::make([
        'id' => 12,
        'number' => '20012',
        'customer_id' => 3,
        'group_id' => 1,
        'state_id' => 2,
        'title' => 'Printer is on fire',
        'article_count' => 1,
        'created_at' => '2026-01-01T10:00:00Z',
        'updated_at' => '2026-01-01T10:00:00Z',
    ]),
]);

$ticket = Zammad::ticket()->show(12);

FakeZammad::assertSent(GetTicketRequest::class);
FakeZammad::assertSentCount(1);
```

- `FakeZammad::fake(array $mocks = []): MockClient`: also reachable as `Zammad::fake(...)`.
- `FakeZammad::assertSent(...)`: assert a request was sent (a request class name or a closure, as in Saloon).
- `FakeZammad::assertNothingSent()`
- `FakeZammad::assertSentCount(int $count)`

Both `MockResponse` and `MockClient` are standard Saloon classes; see the
[Saloon testing docs](https://docs.saloon.dev/the-basics/testing) for the full mock syntax.

## Contributing

Please see [CONTRIBUTING.md](https://github.com/alexhackney/laravel-zammad/blob/main/CONTRIBUTING.md). The project uses Laravel Pint, PHPStan level 8 and Pest:

```bash
composer test
```

## Security

To report a vulnerability, follow [SECURITY.md](https://github.com/alexhackney/laravel-zammad/blob/main/.github/SECURITY.md). Please do not open a public issue.

## Credits

- Original work by codebar Solutions AG and Sebastian Burgin-Fix ([codebar-ag/laravel-zammad](https://github.com/codebar-ag/laravel-zammad))
- Maintained by [Alex Hackney](https://github.com/alexhackney)
- [All contributors](https://github.com/alexhackney/laravel-zammad/graphs/contributors)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
