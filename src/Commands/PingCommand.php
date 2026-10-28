<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Commands;

use AlexHackney\Zammad\Data\User;
use AlexHackney\Zammad\Requests\Users\GetUserRequest;
use AlexHackney\Zammad\Resources\UserResource;
use Illuminate\Console\Command;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Auth\TokenAuthenticator;
use Stringable;
use Throwable;

class PingCommand extends Command
{
    protected $signature = 'zammad:ping {--json : Output the result as JSON}';

    protected $description = 'Check the Zammad URL and token by calling users/me';

    public function handle(): int
    {
        $token = '';

        try {
            $url = $this->configuredValue(config('zammad.url'));

            if ($url === '') {
                return $this->failure('Zammad URL is not configured.');
            }

            $token = $this->configuredValue(config('zammad.token'));

            if ($token === '') {
                return $this->failure('Zammad token is not configured.');
            }

            // Authenticate explicitly so the valid token "0" is not treated as missing.
            $request = (new GetUserRequest)->authenticate(new TokenAuthenticator($token));
            $user = (new UserResource)->request($request)->dtoOrFail();

            return $this->success($user, $this->host($url), $token);
        } catch (Throwable $exception) {
            return $this->failure($this->safeError($exception), $token);
        }
    }

    private function configuredValue(mixed $value): string
    {
        return is_string($value) || $value instanceof Stringable ? (string) $value : '';
    }

    private function safeError(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            return 'HTTP '.$exception->getResponse()->status();
        }

        if ($exception instanceof FatalRequestException && $exception->getPrevious() !== null) {
            return 'Connection failed ('.class_basename($exception->getPrevious()).')';
        }

        // Only these literal errors, originating in our connector, are safe to display.
        if ($exception::class === \Exception::class
            && $exception->getCode() === 500
            && $exception->getFile() === dirname(__DIR__).'/ZammadConnector.php') {
            return match ($exception->getMessage()) {
                'No url provided.' => 'No url provided.',
                'No token provided.' => 'No token provided.',
                default => 'Unexpected Exception error (details withheld)',
            };
        }

        return 'Unexpected '.class_basename($exception).' error (details withheld)';
    }

    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    private function success(User $user, string $host, string $token): int
    {
        $login = $this->redact($user->login, $token);

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => true,
                'login' => $login,
                'id' => $user->id,
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->info($this->redact('Connected to '.$host.' as '.$login, $token));
        }

        return self::SUCCESS;
    }

    private function failure(string $message, string $token = ''): int
    {
        $message = $this->redact($message, $token);

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    private function redact(string $message, string $token): string
    {
        if ($token !== '') {
            $message = str_replace($token, '[REDACTED]', $message);
        }

        $message = preg_replace('/\bAuthorization\b[\'" ]*[:=]\s*[^\r\n]*/i', 'Authorization: [REDACTED]', $message) ?? 'Zammad request failed.';
        $message = preg_replace('/\bBearer\s+\S+/i', '[REDACTED]', $message) ?? 'Zammad request failed.';
        $message = preg_replace('/[\x00-\x1F\x7F]/', ' ', $message) ?? 'Zammad request failed.';

        return mb_substr($message !== '' ? $message : 'Zammad request failed.', 0, 200);
    }
}
