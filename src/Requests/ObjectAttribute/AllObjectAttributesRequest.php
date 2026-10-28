<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\ObjectAttribute;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class AllObjectAttributesRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/object_manager_attributes';
    }
}
