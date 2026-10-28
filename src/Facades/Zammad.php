<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Facades;

use AlexHackney\Zammad\Resources\AttachmentResource;
use AlexHackney\Zammad\Resources\CommentResource;
use AlexHackney\Zammad\Resources\ObjectAttributeResource;
use AlexHackney\Zammad\Resources\TicketResource;
use AlexHackney\Zammad\Resources\UserAccessToken;
use AlexHackney\Zammad\Resources\UserResource;
use AlexHackney\Zammad\Testing\FakeZammad;
use Illuminate\Support\Facades\Facade;
use Saloon\Http\Faking\MockClient;

/**
 * @see \AlexHackney\Zammad\Zammad
 *
 * @method static UserResource user()
 * @method static UserAccessToken userAccessToken()
 * @method static TicketResource ticket()
 * @method static CommentResource comment()
 * @method static AttachmentResource attachment()
 * @method static ObjectAttributeResource object()
 * @method static MockClient fake(array<int|string, mixed> $mocks = [])
 */
class Zammad extends Facade
{
    /**
     * Install Saloon's global mock client for Zammad requests.
     * Each call replaces the previous global client, mocks and request history.
     *
     * @param  array<int|string, mixed>  $mocks
     */
    public static function fake(array $mocks = []): MockClient
    {
        return FakeZammad::fake($mocks);
    }

    protected static function getFacadeAccessor(): string
    {
        return \AlexHackney\Zammad\Zammad::class;
    }
}
