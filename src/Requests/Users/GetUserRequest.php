<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\Users;

use AlexHackney\Zammad\Data\User;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

class GetUserRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        public ?int $id = null,
        public bool $expand = false
    ) {}

    public function resolveEndpoint(): string
    {
        $endpoint = '/users/';

        if ($this->id !== null) {
            return $endpoint.$this->id;
        }

        return $endpoint.'me';
    }

    protected function defaultQuery(): array
    {
        return [
            'expand' => $this->expand,
        ];
    }

    public function createDtoFromResponse(Response $response): User
    {
        return User::fromJson($response->json(), $this->expand);
    }
}
