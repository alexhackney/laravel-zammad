<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\Comments;

use AlexHackney\Zammad\Data\Comment;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

class GetCommentRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        public int $id
    ) {}

    public function resolveEndpoint(): string
    {
        return '/ticket_articles/'.$this->id;
    }

    public function createDtoFromResponse(Response $response): Comment
    {
        return Comment::fromJson($response->json());
    }
}
