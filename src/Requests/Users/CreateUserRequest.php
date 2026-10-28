<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\Users;

use AlexHackney\Zammad\Data\User;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

class CreateUserRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /** @param array<string, mixed> $payload */
    public function __construct(
        protected array $payload,
        public bool $expand = false
    ) {}

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    protected function defaultQuery(): array
    {
        return [
            'expand' => $this->expand,
        ];
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return $this->payload;
    }

    public function createDtoFromResponse(Response $response): User
    {
        return User::fromJson($response->json(), $this->expand);
    }
}
