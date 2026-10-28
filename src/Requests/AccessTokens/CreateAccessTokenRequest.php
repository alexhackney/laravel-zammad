<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\AccessTokens;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreateAccessTokenRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /** @param array<string, mixed> $payload */
    public function __construct(
        protected array $payload
    ) {}

    public function resolveEndpoint(): string
    {
        return '/user_access_token';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return $this->payload;
    }
}
