# Upgrading from `codebar-ag/laravel-zammad` to `alexhackney/laravel-zammad`

This guide covers migrating a Laravel application (or package) from `codebar-ag/laravel-zammad` (or a VCS fork of it) to `alexhackney/laravel-zammad`. It is written for both developers and automated coding agents.

---

## 1. Summary Mapping Table

| Item | Before (`codebar-ag/laravel-zammad`) | After (`alexhackney/laravel-zammad`) |
| --- | --- | --- |
| Composer package name | `codebar-ag/laravel-zammad` | `alexhackney/laravel-zammad` |
| Root namespace | `CodebarAg\Zammad` | `AlexHackney\Zammad` |
| Facade | `CodebarAg\Zammad\Facades\Zammad` | `AlexHackney\Zammad\Facades\Zammad` |
| Connector | `CodebarAg\Zammad\ZammadConnector` | `AlexHackney\Zammad\ZammadConnector` |
| Service provider | `CodebarAg\Zammad\ZammadServiceProvider` | `AlexHackney\Zammad\ZammadServiceProvider` |
| DTO namespace and directory | `CodebarAg\Zammad\DTO\*` (`src/DTO/`) | `AlexHackney\Zammad\Data\*` (`src/Data/`) |
| `Attachment` DTO | `CodebarAg\Zammad\DTO\Attachment` | `AlexHackney\Zammad\Data\Attachment` |
| `Comment` DTO | `CodebarAg\Zammad\DTO\Comment` | `AlexHackney\Zammad\Data\Comment` |
| `ObjectAttribute` DTO | `CodebarAg\Zammad\DTO\ObjectAttribute` | `AlexHackney\Zammad\Data\ObjectAttribute` |
| `Ticket` DTO | `CodebarAg\Zammad\DTO\Ticket` | `AlexHackney\Zammad\Data\Ticket` |
| `User` DTO | `CodebarAg\Zammad\DTO\User` | `AlexHackney\Zammad\Data\User` |
| Resources namespace | `CodebarAg\Zammad\Resources\*` | `AlexHackney\Zammad\Resources\*` |
| Requests namespace | `CodebarAg\Zammad\Requests\*` | `AlexHackney\Zammad\Requests\*` |
| Response log event | `CodebarAg\Zammad\Events\ZammadResponseLog` | `AlexHackney\Zammad\Events\ZammadResponseLog` |
| Renamed config key | `zammad.objet_reference_error` | `zammad.object_reference_error` |
| Config `object_reference_error` default | `'Can&#39;t delete, object has references.'` | `"Can't delete, object has references."` |
| Added timeout config keys | *(none)* | `zammad.connect_timeout` (`ZAMMAD_CONNECT_TIMEOUT`, default `10`), `zammad.request_timeout` (`ZAMMAD_REQUEST_TIMEOUT`, default `30`) |
| PHP requirement | `^8.2` | `^8.3` |
| Laravel requirement (`illuminate/contracts`, `illuminate/support`) | `^12.0` | `^12.0\|^13.0` |
| Saloon requirement (`saloonphp/saloon`, `saloonphp/laravel-plugin`) | `^3.0` | `^4.0` |
| Added runtime dependency | *(none)* | `symfony/html-sanitizer: ^7.0\|^8.0` |
| Removed runtime dependency | `saloonphp/cache-plugin` | *(removed; was unused)* |
| Removed / replaced dev dependencies | `spatie/laravel-ray`, `friendsofphp/php-cs-fixer` | `spatie/laravel-ray` removed; `php-cs-fixer` replaced by `laravel/pint` (`^1.0`), `larastan/larastan` (`^3.10`), `pestphp/pest` (`^4.0`) |

---

## 2. Migrating from a VCS Fork Repository in `composer.json`

If your application previously installed this fork via a Composer `repositories` entry pointing at a `laravel-zammad` Git URL (for example with a branch alias constraint like `"dev-main as 12.9.1"` or `"dev-fix-saloon as 12.9.1"`), remove the VCS override before requiring the Packagist release:

### Before (`composer.json`)

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/alexhackney/laravel-zammad"
        }
    ],
    "require": {
        "codebar-ag/laravel-zammad": "dev-main as 12.9.1"
    }
}
```

### After (`composer.json`)

1. Rewrite PHP/Blade namespaces and provider registrations first, including `bootstrap/providers.php`, published configuration, and Composer's `extra.laravel.providers`, `extra.laravel.aliases`, and `autoload` / `autoload-dev` PSR-4 keys and values. Map only `CodebarAg\Zammad\DTO` to `AlexHackney\Zammad\Data`, then the remaining `CodebarAg\Zammad` root to `AlexHackney\Zammad`. Update `DTO\` members of root grouped imports to `Data\`, and finish any already-renamed `AlexHackney\Zammad\DTO` references by changing `DTO` to `Data`.
2. Remove only `type: vcs` / `type: git` repositories whose URL path ends in `/laravel-zammad` or `/laravel-zammad.git`, on any host (including scp-style Git URLs). Keep `path`, `composer`, and `artifact` repositories and other package URLs. Delete `repositories` if it becomes empty.
3. Remove `codebar-ag/laravel-zammad` with scripts disabled, then require `alexhackney/laravel-zammad` from Packagist:

```bash
composer remove codebar-ag/laravel-zammad --no-interaction --no-scripts
composer require alexhackney/laravel-zammad --no-interaction
```

Run `composer install` or `composer dump-autoload` afterwards to run application scripts skipped during removal. Only tolerate a remove failure when the old package is absent from both `require` and `require-dev`. Running `bin/migrate-from-codebar` performs these steps in this order; `--skip-composer` still edits the manifest but leaves both Composer commands for you to run manually.

If the old package is in `require-dev`, add `--dev` to both commands. If it is in both requirement sections, run the commands once without `--dev` and once with `--dev`. The helper detects these sections automatically.

---

## 3. Breaking Behaviour Changes and Fixes

Every behaviour change from `CHANGELOG.md` (`[Unreleased]`) that can affect an application is listed below with its exact before/after behaviour and the required action.

### 3.1 HTTP Retry Policy (`ZammadConnector::handleRetry()`)

- **Before**: Every failed request was retried up to `config('zammad.http_retry_maximum')` times (waiting `config('zammad.http_retry_delay')` ms between tries), regardless of HTTP method or response status. `POST` requests and `4xx` client errors were retried.
- **After**: Failed requests are retried only when safe:
  - Idempotent methods (`GET`, `HEAD`, `PUT`, `DELETE`) retry on connection failures (`FatalRequestException`), HTTP `429`, and `5xx` (`500` to `599`).
  - Non-idempotent methods (`POST`) retry only on `FatalRequestException` when the underlying Guzzle `ConnectException` has cURL errno `6` (`CURLE_COULDNT_RESOLVE_HOST`) or `7` (`CURLE_COULDNT_CONNECT`), meaning the connection never opened.
  - `4xx` responses other than `429` are never retried.
- **What to do**: Do not rely on the connector to retry `POST` requests that fail after a connection is established or to retry `4xx` errors. If you need retries for ticket/user/comment creation, wrap the call in a queued job or application-level retry with idempotency checks.

### 3.2 Redirects (`3xx`) Treated as Failed Requests (`ZammadConnector`)

- **Before**: Guzzle followed HTTP `3xx` redirects by default, and `3xx` responses were not marked as failures. A `POST`, `PUT`, or `DELETE` could be silently converted to a `GET` or replayed across a redirect.
- **After**: `ZammadConnector::defaultConfig()` sets `'allow_redirects' => false`, `hasRequestFailed()` returns `true` for any redirect (`3xx`) response, and `getRequestException()` returns a `Saloon\Exceptions\Request\RequestException` with message `'Unexpected redirect (HTTP <status>)'`. Additionally, when `config('zammad.url')` starts with `http://`, `ZammadConnector::resolveBaseUrl()` logs a warning once (`The Zammad bearer token would be sent unencrypted over HTTP.`).
- **What to do**: Set `ZAMMAD_URL` directly to the canonical `https://` URL of your Zammad instance so it does not respond with an `http://` to `https://` or host redirect.

### 3.3 Internal Articles Excluded by Default (`includeInternal`)

- **Before**: `CommentResource::showByTicket(int $id)` and `TicketResource::showWithComments(int $id)` returned all comments on the ticket, including internal agent notes (`internal === true`).
- **After**: Both methods now have the signature `(int $id, bool $includeInternal = false)` and filter out comments where `$comment['internal']` is truthy unless `includeInternal: true` is passed.
- **What to do**:
  - Keep the default (`includeInternal: false`) for customer-facing views so internal notes are not leaked.
  - Pass `includeInternal: true` in agent/admin tools or synchronization jobs that need internal notes:
    ```php
    $comments = Zammad::comment()->showByTicket($ticketId, includeInternal: true);
    $ticket = Zammad::ticket()->showWithComments($ticketId, includeInternal: true);
    ```

### 3.4 `UserResource::show(0)` Rejected (Use `me()`)

- **Before**: `UserResource::show(int $id = 0)` accepted `0` (or no argument) and resolved it to `/users/me` (the current authenticated user).
- **After**: `UserResource::show(int $id): User` requires `$id` and throws `\InvalidArgumentException` (`The user id must be a positive integer. Use me() for the current user.`) when `$id < 1`.
- **What to do**:
  - Replace `Zammad::user()->show(0)` and `Zammad::user()->show()` with `Zammad::user()->me()`.
  - Ensure any variable `$id` passed to `Zammad::user()->show($id)` is validated as an integer `>= 1`.

### 3.5 `UserResource::searchOrCreateByEmail()` Exact Matching, Validation, and `$data`

- **Before**:
  - `searchOrCreateByEmail(string $email, array $data = [])` ran `search("email:{$email}")` and returned the first result even when the returned user's email did not match `$email` (for example, a substring or secondary field match).
  - Did not reject whitespace or Zammad search syntax characters in `$email`.
  - Dropped extra attributes in `$data` when creating a new user.
- **After**:
  - Throws `\InvalidArgumentException` before sending a request if `$email === ''` or `$email` contains whitespace or any of `"`, `\`, `(`, `)`, `*`, `?`, `:`.
  - Returns an existing `User` only when `mb_strtolower(trim($user->email), 'UTF-8') === mb_strtolower(trim($email), 'UTF-8')`.
  - When creating a user, calls `$this->create(array_merge($data, ['email' => $email]))`, preserving all extra attributes in `$data` while ensuring `'email'` is always `$email`. Existing users are returned as-is without calling `update()`.
- **What to do**: Trim and validate email addresses before passing them to `searchOrCreateByEmail()`, handle `\InvalidArgumentException` for untrusted input, and explicitly call `Zammad::user()->update($user->id, $data)` if you need to update attributes on an already-existing user.

### 3.6 `UserResource::update()` and `UserAccessToken::delete()` ID Validation and Return Types

- **Before**: `UserResource::update()` and `UserAccessToken::delete()` did not validate that `$id` was a positive integer. `UserAccessToken::list()`, `create()`, and `delete()` lacked strict `array` return type declarations.
- **After**:
  - `UserResource::update(int|string $id, array $data): User` and `UserAccessToken::delete(int|string $id): array` validate `$id` with `filter_var($id, FILTER_VALIDATE_INT)` and throw `\InvalidArgumentException` when `$id === false || $id < 1`.
  - `UserAccessToken::list(): array`, `UserAccessToken::create(array $data): array`, and `UserAccessToken::delete(int|string $id): array` are strictly typed to return `array`.
- **What to do**: Ensure `$id` passed to `user()->update($id, $data)` and `userAccessToken()->delete($id)` is a positive integer (or a positive integer string such as `"42"`). Do not pass token strings/names, `0`, negative numbers, or non-numeric strings.

### 3.7 `Comment::$body_filtered` Sanitised with `symfony/html-sanitizer`

- **Before**:
  - `Comment::$body_filtered` stripped signatures, `<img>`, and `<table>` tags with string operations.
  - Stripping `filter_data_signature_value` left a stray closing `</div>` in `$body_filtered`.
  - Unsafe HTML (`<script>`, inline `on*` event handlers, `javascript:` URLs, `<style>`, `<iframe>`, `<svg>`, `<form>`, etc.) was not sanitised.
  - Plain-text comments (`content_type` without `html`) were not HTML-escaped.
- **After**:
  - Input to `$body_filtered` is capped at `200000` bytes (`mb_strcut($data['body'], 0, 200000, 'UTF-8')`), while `Comment::$body` keeps the full untruncated string.
  - For HTML comments (`str_contains(strtolower($data['content_type']), 'html')`) whose tag nesting depth does not exceed `100`:
    - Configured signature markers (`filter_signature_marker_value` and `filter_data_signature_value`) are removed first.
    - The HTML is sanitised with `Symfony\Component\HtmlSanitizer\HtmlSanitizer`: allows safe elements, allows `http`/`https`/`mailto` link schemes and `http`/`https` media schemes, drops `srcset` and unsafe URL attributes (`imagesrcset`, `manifest`, `usemap`, `conversiondestination`, `reportingorigin`, `resources`, `code`, `object`), drops `form`, `input`, `button`, `select`, `option`, `textarea`, `title`, `style`, `script`, `iframe`, `object`, `embed`, `template`, `plaintext`, `xmp`, `listing`, `noembed`, `noframes`, `noscript`, and `svg`.
    - When `config('zammad.filter_images')` is `true`, `img` elements are dropped.
    - When `config('zammad.filter_tables')` is `true`, `table`, `thead`, `tbody`, `tfoot`, `tr`, `th`, `td`, and `caption` are unwrapped as block elements.
    - The stray closing `</div>` is gone.
  - For plain-text comments (or HTML exceeding a nesting depth of `100`), `$body_filtered` is HTML-escaped with `htmlspecialchars($bodyFiltered, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- **What to do**:
  - Audit Blade templates that render `{!! $comment->body_filtered !!}` or `{{ $comment->body_filtered }}`.
  - Because plain-text comments now have HTML entities escaped in `$body_filtered`, rendering `{{ $comment->body_filtered }}` on a plain-text comment will double-escape `&`, `<`, `>`, `"`, and `'`; use `$comment->body` when rendering with `{{ ... }}` or `{!! $comment->body_filtered !!}` when rendering filtered HTML.

### 3.8 `object_reference_error` Config Key Rename and `object_reference_error_ignore` Fix

- **Before**:
  - The config key was misspelled `'objet_reference_error'` in `config/zammad.php`, with an HTML-encoded default `'Can&#39;t delete, object has references.'` that never matched a decoded error string.
  - `RequestClass::deleteRequest()` called `$this->request()`, which threw `RequestException` before checking `status() === 422`, so `object_reference_error_ignore => true` never worked.
- **After**:
  - The config key is now `'object_reference_error'` with default `"Can't delete, object has references."` (plain apostrophe). The environment variable name remains `ZAMMAD_OBJECT_REFERENCE_ERROR`.
  - `RequestClass::deleteRequest()` sends the request with `throw: false`. When `config('zammad.object_reference_error_ignore')` is `true` and the response status is `422`, it compares the configured message (HTML entities decoded on both sides) with the JSON `error` field when the body is JSON, or with the whole raw body when the body is not JSON at all (for example a plain-text 422), and returns the `Response` without throwing when it matches. JSON that cannot be parsed for resource-limit reasons fails closed. Unrelated `422` responses and other error statuses still throw `RequestException`.
- **What to do**:
  - Rename `'objet_reference_error'` to `'object_reference_error'` in your published `config/zammad.php` (and any `config('zammad.objet_reference_error')` calls).
  - If you set `ZAMMAD_OBJECT_REFERENCE_ERROR` in `.env`, verify it matches `"Can't delete, object has references."` (HTML entities are decoded before comparison, so both plain and entity-encoded apostrophes match).
  - If `ZAMMAD_OBJECT_REFERENCE_ERROR_IGNORE=true` is enabled in your environment, note that `delete()` calls encountering a `422` object-reference error will now succeed silently instead of throwing `RequestException`.

### 3.9 Strict DTO Hydration, `Ticket::$number` Type, `Ticket` Comments/Casts, `expand()`, and `Attachment::url()`

- **Before**:
  - Zammad returns ticket `number` as a numeric string (for example `"69032"`), which could remain a string or rely on weak scalar coercion.
  - `Ticket::fromJson()` ignored `comments` in `$data`, and `new Ticket(...)` discarded comments passed to the constructor.
  - Dynamic ticket attributes in `config('zammad.ticket')` could overwrite declared properties on `Ticket`, the documented `'string'` cast was not implemented, and `'boolean'` cast used `(bool) $value` (treating `"false"`, `"0"`, `"no"`, `"off"` as `true`).
  - `CreateTicketRequest`, `CreateUserRequest`, and `UpdateUserRequest` ignored `expand()`.
  - `Attachment::url()` only checked whether `config('zammad.url')` was truthy.
- **After**:
  - All `AlexHackney\Zammad\Data\*` classes (`Attachment`, `Comment`, `ObjectAttribute`, `Ticket`, `User`) use `declare(strict_types=1)` and `NormalizesJsonScalars` (`jsonInt`, `jsonString`, `jsonBool`), throwing `\TypeError` if JSON fields are non-scalar or non-numeric where an `int` is required.
  - `Ticket::$number` is normalized via `jsonInt()` and is always an `int`.
  - `Ticket::fromJson()` hydrates `$data['comments']` into a `Collection<int, Comment>` (and `new Ticket(...)` preserves the `$comments` collection passed to it).
  - Dynamic ticket attributes in `config('zammad.ticket')` skip any key that matches a declared property on `Ticket` (`! property_exists(self::class, $key)`). The `'string'` cast casts to `(string)`, and `'bool'`/`'boolean'` uses `filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $data[$key]` (so `"false"`, `"0"`, `"no"`, and `"off"` evaluate to `false`).
  - `TicketResource::create()`, `UserResource::create()`, and `UserResource::update()` now pass `expand` and populate `$dto->expanded` when `expand()` is chained.
  - `Attachment::url()` throws the generic `\Exception('No url provided.', 500)` when URL configuration is unset or empty. For a non-empty value it validates `config('zammad.url')` with `parse_url()` and throws `\InvalidArgumentException` (`The Zammad url must be an absolute HTTP or HTTPS URL.`) if the scheme is not `http`/`https` or the host is missing.
- **What to do**:
  - Update strict string comparisons on `$ticket->number` (for example `$ticket->number === '69032'`) to compare against an `int` (`(int) $number`) or cast `(string) $ticket->number`.
  - Check any custom ticket attribute casts in `config/zammad.php`.
  - Ensure `config('zammad.url')` in tests and `.env` is a full `http://` or `https://` URL with a host.

### 3.10 `ZammadResponseLog` Dispatched on Failed Responses

- **Before**: `ZammadResponseLog` was dispatched only when `$this->connector->send($request)` returned without throwing.
- **After**: `RequestClass::performRequest()` catches `Saloon\Exceptions\Request\RequestException`, dispatches `new ZammadResponseLog($e->getResponse())` whenever `$e->getResponse() !== null`, and rethrows `$e`. Note that `$response->getPendingRequest()` carries the `Authorization` bearer token header.
- **What to do**:
  - Update any event listeners for `AlexHackney\Zammad\Events\ZammadResponseLog` that assumed `$event->response->successful()` is always `true`.
  - Ensure listeners do not log the full response object or pending request headers containing the `Authorization` bearer token.

---

## 4. Step-by-Step Upgrade Checklist (Verbatim Agent Instructions)

Follow these steps in order from the target application's root directory:

1. **Verify platform requirements**:
   Ensure the application runs on PHP `^8.3` and Laravel `^12.0|^13.0`.
2. **Preview automated changes with `--dry-run`**:
   ```bash
   vendor/bin/migrate-from-codebar --dry-run .
   ```
   (Or run `/path/to/migrate-from-codebar --dry-run .` before installing the package.)
3. **Run the migration script**:
   ```bash
   /path/to/migrate-from-codebar .
   ```
   This first rewrites the old Zammad namespaces in PHP/Blade sources and Composer registrations, renames only array keys in `config/zammad.php`, and removes matching VCS/Git repository overrides. It then runs `composer remove codebar-ag/laravel-zammad --no-interaction --no-scripts` followed by `composer require alexhackney/laravel-zammad --no-interaction`. Provider registrations are therefore ready before Composer runs application scripts. Run `composer install` or `composer dump-autoload` afterwards if scripts were skipped.
4. **Update `config/zammad.php` (if published)**:
   - Confirm `'objet_reference_error'` is renamed to `'object_reference_error'` and its default is `"Can't delete, object has references."`.
   - Optionally add the new timeout settings if missing:
     ```php
     'connect_timeout' => env('ZAMMAD_CONNECT_TIMEOUT', 10),
     'request_timeout' => env('ZAMMAD_REQUEST_TIMEOUT', 30),
     ```
5. **Resolve every item in the script's `Review manually:` output**:
   - Replace `->user()->show(0)` or `->user()->show()` with `->user()->me()`, and ensure user IDs passed to `->show($id)` are positive integers.
   - Review `searchOrCreateByEmail()` calls for invalid query characters, exact email matching, and whether existing users also need `->update()`.
   - Add `includeInternal: true` to `showByTicket()` and `showWithComments()` calls that require internal agent notes.
   - Inspect `{!! ... $comment->body_filtered ... !!}` in Blade views to verify sanitised HTML and escaped plain-text rendering.
   - Check `ZammadResponseLog` listeners so they handle failed HTTP responses (`4xx`/`5xx`/`3xx`) and never log the `Authorization` header.
   - Check `ZAMMAD_OBJECT_REFERENCE_ERROR` in `.env` / config files and verify delete error handling if `ZAMMAD_OBJECT_REFERENCE_ERROR_IGNORE=true`.
   - Verify `->update($id, ...)` on `UserResource` and `->delete($id)` on `UserAccessToken` pass positive integers or positive integer strings. Check reported `delete()` calls against the resource's own contract.
6. **Clear cached Laravel configuration and run tests / static analysis**:
   ```bash
   php artisan config:clear
   php artisan test
   ```

---

## 5. Automated Migration Script (`bin/migrate-from-codebar`)

### Usage

```bash
bin/migrate-from-codebar [--dry-run] [--skip-composer] [APP_DIR]
```

- `APP_DIR`: Exact path to the application root containing `composer.json`; defaults to `.`. Whitespace, including trailing newlines, is part of the path. A missing directory or manifest, a symlink in the application path, or a symlinked or hard-linked `composer.json` / `composer.lock` causes a non-zero refusal before any edits. Links are refused to prevent writes to another file or application, including writes made by Composer.
- `--dry-run`: Changes nothing and never runs Composer. Prints `Dry run: changed nothing.`, `Files that would change:`, and `Review manually:`.
- `--skip-composer`: Skips only Composer commands. Source and manifest metadata edits still happen; the commands to run manually are printed.

### Runtime and Order of Operations

The executable is a single dependency-free PHP CLI script requiring PHP 8.3 or later. It does not load the application or use Bash, Perl, Python, find, sort, or grep. Composer is launched with an argument array, without a shell, and always receives `--working-dir=APP_DIR`. Its environment inherits the current environment except for `COMPOSER` and `COMPOSER_ROOT_VERSION`, which are ignored to prevent another manifest or root version from affecting the operation. `COMPOSER_HOME` and `COMPOSER_CACHE_DIR` are preserved if set.

1. Read the manifest and eligible source files, prepare rewrites, and build the review report. Invalid JSON or an integer literal longer than 15 digits outside a JSON string causes a non-zero refusal with no edits or Composer calls. The conservative integer limit prevents precision loss when PHP decodes and re-encodes the manifest. The refusal prints manual steps for removing the repository entry and renaming namespaces; quoted numeric strings are accepted.
2. Write every prepared source and manifest change before invoking Composer. Each change is written to a randomly named temporary file in the same directory, given the original permissions with `chmod`, then atomically renamed over the original. On failure the temporary file is removed and the failing original remains byte-identical. Any scan, read, or write failure aborts with a non-zero exit and an error message. Earlier successful writes are not rolled back; inspect them before retrying.
3. Unless previewing or skipping Composer, run:
   ```bash
   composer remove codebar-ag/laravel-zammad --no-interaction --no-scripts
   composer require alexhackney/laravel-zammad --no-interaction
   ```
   For an old dependency in `require-dev` only, both commands receive `--dev`. If it appears in both sections, the helper runs both pairs, first without `--dev`, then with `--dev`. The printed manual commands use the same section flags. Remove failure is tolerated only if neither `require` nor `require-dev` declares the old package. Require failure always aborts. Run `composer install` or `composer dump-autoload` afterwards to run application scripts skipped during removal. If Composer is absent from `PATH`, the script prints the manual commands and fails, unless `--skip-composer` is passed (also pass it for an offline preview).
4. Print `Files changed:` (or the preview heading) and `Review manually:`, using `none` for an empty section.

### What the Script Changes

- Only text `*.php` (including `*.blade.php`) files under `app`, `bootstrap`, `config`, `database`, `routes`, `resources`, `tests`, `src`, and `lib` are eligible. Nested `vendor`, `node_modules`, `storage`, and `.git` trees are excluded. Files containing NUL bytes are skipped. PHP files containing a serialized-string literal mentioning `CodebarAg` are also skipped entirely and listed under `Review manually` as `Skipped serialized-string literal`, including literals with escaped quotes. Changing a serialized namespace without updating its length prefix corrupts the value; migrate those values manually. Serialized/binary configuration files and other extensions are untouched.
- Namespace replacements map `CodebarAg\Zammad\DTO` to `AlexHackney\Zammad\Data`, followed by the remaining `CodebarAg\Zammad` root. Already-renamed `AlexHackney\Zammad\DTO` references also become `AlexHackney\Zammad\Data`. In root grouped imports such as `use CodebarAg\Zammad\{DTO\User, Resources\UserResource};`, `DTO\` members become `Data\`, including multiline imports and nested segments below `DTO\`. Single and doubled backslashes, `::class`, imports, and docblocks are supported. Unrelated `App\DTO`, `OtherVendor\DTO`, and other namespaces are untouched. The script rewrites references; it does not move source directories or files.
- Only the string array key `objet_reference_error` in the published `config/zammad.php` becomes `object_reference_error`. Values, comments, other config files, and calls such as `config('zammad.objet_reference_error')` require manual changes. The script does not change the default message or add timeouts.
- `composer.json` provider/alias string values and PSR-4 keys/values are rewritten. Only matching `vcs` / `git` repositories described above are removed. Empty JSON objects stay objects, and edited JSON uses four-space indentation and a trailing newline. Dependency requirements are changed by Composer, not by the metadata rewrite.
- Symlinked source files and directories are never followed. Hard-linked source files are skipped and reported. Linked manifests and lockfiles are refused rather than passed to Composer. Application paths containing symlink components are refused, including links hidden by a trailing `/` or `/.`; use the physical path on systems with aliases such as `/tmp`. File identity and link count are rechecked before each write. Run in a trusted application directory without concurrent file replacement; these checks do not protect against another process swapping paths while Composer runs.
- Root `.env` and `.env.*` files are read only for the review report; they are never rewritten. Linked or NUL-containing env files are skipped.

### Manual Review Report

The report includes `file:line` for each match and scans whole PHP/Blade files mentioning Zammad so multiline expressions are included. PHP files larger than 2 MB (2,097,152 bytes) still receive namespace rewrites, but their review scan is skipped with a note listing the file. Review those files manually. It flags:

- User resource `show()` calls through Zammad accessors, directly constructed `UserResource` instances, or assigned/typed resource variables. Review all IDs; use `me()` for the authenticated user.
- `searchOrCreateByEmail`, `showByTicket`, and `showWithComments` calls.
- Multiline `{!! ... body_filtered ... !!}` rendering and `ZammadResponseLog` references.
- `ZAMMAD_OBJECT_REFERENCE_ERROR` usages in sources and root env files.
- User/user-access-token `update()` and `delete()` calls through the same recognized receivers. Ordinary Eloquent calls are not flagged just because a comment mentions zammad.

This is a regex-based aid, not full PHP type analysis. Audit indirect wrappers, aliases, dynamic method calls, and config references yourself.

Source and metadata rewrites are idempotent. Once `composer.json` requires `alexhackney/laravel-zammad` and no longer requires `codebar-ag/laravel-zammad`, a full rerun skips Composer entirely and says so. `--skip-composer` leaves dependency requirements unchanged, so a later full run still performs the pending Composer commands.
