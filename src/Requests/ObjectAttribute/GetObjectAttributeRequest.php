<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\ObjectAttribute;

use AlexHackney\Zammad\Data\ObjectAttribute;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

class GetObjectAttributeRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        public int $id
    ) {}

    public function resolveEndpoint(): string
    {
        return '/object_manager_attributes/'.$this->id;
    }

    public function createDtoFromResponse(Response $response): ObjectAttribute
    {
        return ObjectAttribute::fromJson($response->json());
    }
}
