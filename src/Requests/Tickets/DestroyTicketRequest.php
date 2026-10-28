<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\Tickets;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DestroyTicketRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        public int $id
    ) {}

    public function resolveEndpoint(): string
    {
        return '/tickets/'.$this->id;
    }
}
