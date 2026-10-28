<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\Comments;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DestroyCommentRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        public int $id
    ) {}

    public function resolveEndpoint(): string
    {
        return '/ticket_articles/'.$this->id;
    }
}
