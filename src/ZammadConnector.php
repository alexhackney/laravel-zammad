<?php

declare(strict_types=1);

namespace AlexHackney\Zammad;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;
use Saloon\Contracts\Authenticator;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\HasTimeout;
use Throwable;

class ZammadConnector extends Connector
{
    use HasTimeout;

    protected float $connectTimeout;

    protected float $requestTimeout;

    private static bool $warnedAboutHttp = false;

    public function __construct()
    {
        $this->connectTimeout = (float) config('zammad.connect_timeout');
        $this->requestTimeout = (float) config('zammad.request_timeout');
    }

    /**
     * @throws \Exception
     */
    public function resolveBaseUrl(): string
    {
        $url = config('zammad.url');

        if (! $url) {
            throw new \Exception('No url provided.', 500);
        }

        $url = rtrim($url instanceof \Stringable ? (string) $url : $url, '/');

        if (str_starts_with(strtolower($url), 'http://') && ! self::$warnedAboutHttp) {
            self::$warnedAboutHttp = true;
            Log::warning('The Zammad bearer token would be sent unencrypted over HTTP.');
        }

        return $url.'/api/v1';
    }

    public function hasRequestFailed(Response $response): ?bool
    {
        return $response->redirect() ? true : null;
    }

    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        return $response->redirect()
            ? new RequestException($response, 'Unexpected redirect (HTTP '.$response->status().')')
            : null;
    }

    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        $idempotent = in_array($request->getMethod(), [Method::GET, Method::HEAD, Method::PUT, Method::DELETE], true);

        if ($exception instanceof FatalRequestException) {
            if ($idempotent) {
                return true;
            }

            $previous = $exception->getPrevious();

            return $previous instanceof ConnectException
                && in_array($previous->getHandlerContext()['errno'] ?? null, [6, 7], true);
        }

        $status = $exception->getResponse()->status();

        return $idempotent && ($status === 429 || ($status >= 500 && $status < 600));
    }

    protected function defaultConfig(): array
    {
        return [
            'allow_redirects' => false,
        ];
    }

    protected function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @throws \Exception
     */
    protected function defaultAuth(): ?Authenticator
    {
        return new TokenAuthenticator($this->setAuth(), 'Bearer');
    }

    /**
     * @throws \Exception
     */
    public function setAuth(): string
    {
        $token = config('zammad.token');

        if (! $token) {
            throw new \Exception('No token provided.', 500);
        }

        return $token instanceof \Stringable ? (string) $token : $token;
    }
}
