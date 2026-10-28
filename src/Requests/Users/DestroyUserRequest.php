<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\Users;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DestroyUserRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        public int $id
    ) {}

    public function resolveEndpoint(): string
    {
        return '/users/'.$this->id;
    }
}
