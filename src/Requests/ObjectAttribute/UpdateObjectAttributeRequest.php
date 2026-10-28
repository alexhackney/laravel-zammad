<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\ObjectAttribute;

use AlexHackney\Zammad\Data\ObjectAttribute;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

class UpdateObjectAttributeRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $id,
        public array $payload
    ) {}

    public function resolveEndpoint(): string
    {
        return '/object_manager_attributes/'.$this->id;
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return $this->payload;
    }

    public function createDtoFromResponse(Response $response): ObjectAttribute
    {
        return ObjectAttribute::fromJson($response->json());
    }
}
