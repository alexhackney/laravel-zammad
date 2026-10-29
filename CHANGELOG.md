# Changelog

Releases are automatic. Every merge to `main` that contains a releasable conventional commit is released:
the version is computed from the commit subjects, and the notes are generated and published on the
[GitHub Releases](https://github.com/alexhackney/laravel-zammad/releases) page. This file holds the
1.0.0 migration summary and the history of the upstream package. See CONTRIBUTING.md for the rules.

## 1.0.0 - migration summary

First release of the maintained fork of `codebar-ag/laravel-zammad` (upstream is no longer developed).
Migrating from `codebar-ag/laravel-zammad`? See [UPGRADING.md](UPGRADING.md).

### Changed (breaking)

- Package renamed to `alexhackney/laravel-zammad`; namespace `CodebarAg\Zammad` is now `AlexHackney\Zammad`.
- `CodebarAg\Zammad\DTO\*` is now `AlexHackney\Zammad\Data\*` (`src/DTO` is now `src/Data`).
- Config key `objet_reference_error` is now `object_reference_error`, and its default is the plain-apostrophe
  text `Can't delete, object has references.` (the HTML-encoded default never matched a decoded message).
- Requires PHP `^8.3`, Laravel `^12.0|^13.0` and Saloon `^4.0` (Saloon 3 and PHP 8.2 are dropped).
- Retries: failed requests are retried only when it is safe. GET, HEAD, PUT and DELETE retry connection
  failures, 5xx and 429; a POST is retried only when the connection never opened. 4xx responses (other than 429)
  are never retried. Previously every failure, including every POST and every 4xx, was retried.
- Redirects are not followed and any 3xx response is treated as a failed request (`RequestException`), so a
  write cannot be replayed or reported as success because of a redirect.
- `CommentResource::showByTicket()` and `TicketResource::showWithComments()` exclude internal articles by default;
  pass `includeInternal: true` to get them.
- `UserResource::show()` rejects ids below 1 (it used to resolve `0` to the current user; use `me()`).
  `UserResource::update()` and `UserAccessToken::delete()` take an `int|string` id that must be a positive integer.
- `UserResource::searchOrCreateByEmail()` returns an existing user only when its email equals the searched email
  (case-insensitive, Unicode-aware), keeps the extra attributes when it creates, and rejects emails containing
  characters that change the search query.
- `Comment::$body_filtered` is now sanitised with `symfony/html-sanitizer` (script, event handlers, unsafe URLs and
  raw-text elements are removed, tables are unwrapped, input is capped at 200000 bytes and very deeply nested markup
  falls back to escaped text). The stray closing `</div>` is gone. Plain-text comments are HTML-escaped.
- `Ticket::fromJson()` hydrates `comments` into `Comment` DTOs; custom ticket attributes no longer overwrite
  declared properties; the documented `string` cast works and boolean casts understand `"false"`, `"0"`, `"no"`, `"off"`.
- DTO hydration is strict-types safe: scalars from Zammad JSON are normalised explicitly (for example the ticket
  `number` arrives as a numeric string and is stored as `int`).
- `UserAccessToken::list()`, `create()` and `delete()` are typed to return `array`.
- `zammad:ping` never prints the token and always exits 1 on any failure, including connection errors and non-JSON
  responses; with `--json` it always prints exactly one JSON object.
- `FakeZammad::fake()` resets any earlier global fake first, so calling it twice installs the new mocks.
- `UserResource::search()` without an explicit limit sends `limit=1`.
- Delete-error matching: a JSON body is matched only by its string `error` field; a body that is not JSON is
  matched as the whole raw body; JSON that cannot be parsed because of resource limits throws (fails closed).
- `Attachment::url()` requires an absolute `http(s)` base URL.

### Fixed

- `object_reference_error_ignore` now works (the 422 used to be thrown before it was inspected). A JSON body is matched
  only by its string `error` field; a non-JSON body is matched as a whole; unparseable JSON throws.
- `searchOrCreateByEmail()` no longer drops custom attributes.
- Create and update requests honour `expand`.
- `Zammad::ticket()->search()` works with current Zammad, which answers with a plain list of tickets (it still accepts the older `assets` format). It used to return an empty collection.
- Ticket comments passed to the constructor are no longer discarded.

### Added

- `connect_timeout` and `request_timeout` config keys (`ZAMMAD_CONNECT_TIMEOUT`, `ZAMMAD_REQUEST_TIMEOUT`).
- A warning is logged once when `ZAMMAD_URL` is plain `http://`.
- The `ZammadResponseLog` event is dispatched once per operation with the final response (after retries) or the final
  failed response.
- `FakeZammad` and `Zammad::fake()` for mocking the package in your tests.
- The `zammad:ping` Artisan command.
- `bin/migrate-from-codebar`, exposed through Composer's `bin` (see UPGRADING.md).
- Environment variables for the comment filters: `ZAMMAD_FILTER_IMAGES`, `ZAMMAD_FILTER_TABLES`,
  `ZAMMAD_FILTER_SIGNATURE_MARKER`, `ZAMMAD_FILTER_SIGNATURE_MARKER_VALUE`, `ZAMMAD_FILTER_DATA_SIGNATURE`,
  `ZAMMAD_FILTER_DATA_SIGNATURE_VALUE` (defaults unchanged).
- Guzzle 7 and 8 are both supported (`guzzlehttp/guzzle ^7.8|^8.3`); Laravel 12 still pins Guzzle 7.
- Laravel Pint, PHPStan level 8 (Larastan), Pest 4, CI for PHP 8.3 to 8.5 on Laravel 12 and 13.

### Removed

- `saloonphp/cache-plugin` (it was never used) and the unused dev dependency `spatie/laravel-ray`; php-cs-fixer is replaced by Pint.

## Upstream history (codebar-ag/laravel-zammad)

### 2.0.2beta - 2022-07-07

#### Critical

**Comment DTO**

- Replaced $body_without_blockquote variable with $body_filtered
- Dropped $body_only_blockquote

#### Core
- Dropped support for PHP versions below < 8.1
- Replaced PHPUnit for PEST
- Moved HTTP requests to a dedicated class `RequestClass.php`

#### Improvements

- Added dynamic retry values for failed HTTP requests that can be set via the configuration file.
- You can now pass User attributes within the searchOrCreateByEmail method to update or create user
  attributes within the same request.
- Added a dynamic HTML Stripe-Out possibiliites via configuration file.
- Define via configuration variable if a 'Unprocessable Entity' response related to an object reference should be
  ignored. [More information](https://docs.zammad.org/en/latest/api/user.html#update).

#### Features

- Create & Update Zammad Objects
- Update Zammad User

### 1.0.0 - 2021-07-22

- Stable release.

### 0.1.2 - 2021-05-19

- Added `origin_by_id` to the comment DTO
- Added `sender` to the comment DTO

### 0.1.1 - 2021-05-18

- Added `body_without_blockquote` and `body_only_blockquote` attribute to the
  comment DTO

### 0.1.0 - 2021-05-18

- [Breaking Change] Changed `user_id` to `customer_id` in the ticket DTO
- Added sender_id for the comment DTO
- Optional subject for the comment DTO
- Added ticket showWithComment endpoint
- Added URL for the attachment DTO

## 0.0.1 - 2021-04-21

- Facade was not registered correct.

## 0.0.0 - 2021-04-21

- initial release
