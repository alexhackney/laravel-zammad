<?php

declare(strict_types=1);

namespace AlexHackney\Zammad;

use AlexHackney\Zammad\Resources\AttachmentResource;
use AlexHackney\Zammad\Resources\CommentResource;
use AlexHackney\Zammad\Resources\ObjectAttributeResource;
use AlexHackney\Zammad\Resources\TicketResource;
use AlexHackney\Zammad\Resources\UserAccessToken;
use AlexHackney\Zammad\Resources\UserResource;

class Zammad
{
    public function user(): UserResource
    {
        return new UserResource;
    }

    public function userAccessToken(): UserAccessToken
    {
        return new UserAccessToken;
    }

    public function ticket(): TicketResource
    {
        return new TicketResource;
    }

    public function comment(): CommentResource
    {
        return new CommentResource;
    }

    public function attachment(): AttachmentResource
    {
        return new AttachmentResource;
    }

    public function object(): ObjectAttributeResource
    {
        return new ObjectAttributeResource;
    }
}
