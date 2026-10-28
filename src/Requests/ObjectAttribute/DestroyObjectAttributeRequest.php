<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\ObjectAttribute;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DestroyObjectAttributeRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        public int $id
    ) {}

    public function resolveEndpoint(): string
    {
        return '/object_manager_attributes/'.$this->id;
    }
}
