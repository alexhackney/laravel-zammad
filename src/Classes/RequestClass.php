<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Classes;

use AlexHackney\Zammad\Events\ZammadResponseLog;
use AlexHackney\Zammad\ZammadConnector;
use Illuminate\Support\Str;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Throwable;

abstract class RequestClass
{
    protected int $httpRetryMaxium;

    protected int $httpRetryDelay;

    protected bool $ignoreReferenceErrorIngore;

    protected string $objectHasReferenceError;

    protected ZammadConnector $connector;

    public function __construct()
    {
        $this->httpRetryMaxium = (int) config('zammad.http_retry_maximum');
        $this->httpRetryDelay = (int) config('zammad.http_retry_delay');

        $this->ignoreReferenceErrorIngore = (bool) config('zammad.object_reference_error_ignore');
        $this->objectHasReferenceError = (string) config('zammad.object_reference_error');

        $this->connector = new ZammadConnector;
    }

    private function performRequest(Request $request, bool $throw = true): Response
    {
        try {
            $request->tries = $this->httpRetryMaxium;
            $request->retryInterval = $this->httpRetryDelay;
            $request->throwOnMaxTries = $throw;
            $request->useExponentialBackoff = false;

            $response = $this->connector->send($request);
        } catch (RequestException $e) {
            if ($e->getResponse() !== null) {
                event(new ZammadResponseLog($e->getResponse()));
            }

            throw $e;
        }

        event(new ZammadResponseLog($response));

        return $response;
    }

    /**
     * @throws RequestException|Throwable
     */
    public function request(Request $request): Response
    {
        $response = $this->performRequest($request);

        return $response->throw();
    }

    /**
     * @throws RequestException|Throwable
     */
    public function deleteRequest(Request $request): Response
    {
        $response = $this->performRequest($request, throw: false);

        if ($this->ignoreReferenceErrorIngore && $response->status() === 422) {
            $body = $response->body();
            $json = json_decode($body, true, 2048);
            $error = '';

            if (json_last_error() === JSON_ERROR_NONE) {
                $error = is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : '';
            } elseif (in_array(json_last_error(), [
                JSON_ERROR_STATE_MISMATCH,
                JSON_ERROR_CTRL_CHAR,
                JSON_ERROR_SYNTAX,
                JSON_ERROR_UTF8,
                JSON_ERROR_UTF16,
            ], true)) {
                $error = $body;
            }

            if (Str::contains(
                html_entity_decode($error, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                html_entity_decode($this->objectHasReferenceError, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            )) {
                return $response;
            }
        }

        return $response->throw();
    }
}
