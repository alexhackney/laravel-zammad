<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Requests\Comments;

use AlexHackney\Zammad\Data\Comment;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

class CreateCommentRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /** @param array<string, mixed> $payload */
    public function __construct(
        protected array $payload
    ) {}

    public function resolveEndpoint(): string
    {
        return '/ticket_articles';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return $this->payload;
    }

    public function createDtoFromResponse(Response $response): Comment
    {
        return Comment::fromJson($response->json());
    }
}
