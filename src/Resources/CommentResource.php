<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Resources;

use AlexHackney\Zammad\Classes\RequestClass;
use AlexHackney\Zammad\Data\Comment;
use AlexHackney\Zammad\Requests\Comments\CreateCommentRequest;
use AlexHackney\Zammad\Requests\Comments\DestroyCommentRequest;
use AlexHackney\Zammad\Requests\Comments\GetCommentByTicketRequest;
use AlexHackney\Zammad\Requests\Comments\GetCommentRequest;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\RequestException;

class CommentResource extends RequestClass
{
    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    public function show(int $id): ?Comment
    {
        $response = self::request(new GetCommentRequest($id));

        return $response->dtoOrFail();
    }

    /**
     * @return Collection<int, Comment>
     *
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    public function showByTicket(int $id, bool $includeInternal = false): Collection
    {
        $response = self::request(new GetCommentByTicketRequest($id));

        $collection = $response->collect();

        if (! $includeInternal) {
            $collection = $collection->reject(fn (array $comment) => $comment['internal'])->values();
        }

        return $collection->map(fn (array $comment) => Comment::fromJson($comment));
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @param array<string, mixed> $data */
    public function create(array $data): Comment
    {
        $response = self::request(new CreateCommentRequest($data));

        return $response->dtoOrFail();
    }

    public function delete(int $id): void
    {
        self::deleteRequest(new DestroyCommentRequest($id));
    }
}
