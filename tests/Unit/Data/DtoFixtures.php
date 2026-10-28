<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Tests\Unit\Data;

use AlexHackney\Zammad\Data\Attachment;
use AlexHackney\Zammad\Data\Comment;
use AlexHackney\Zammad\Data\ObjectAttribute;
use AlexHackney\Zammad\Data\Ticket;
use AlexHackney\Zammad\Data\User;

final class DtoFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function hydrate(string $dto, array $overrides = []): Attachment|Comment|ObjectAttribute|Ticket|User
    {
        return match ($dto) {
            Attachment::class => Attachment::fromJson(self::attachment($overrides), 2, 3),
            Comment::class => Comment::fromJson(self::comment($overrides)),
            ObjectAttribute::class => ObjectAttribute::fromJson(self::objectAttribute($overrides)),
            Ticket::class => Ticket::fromJson(self::ticket($overrides)),
            User::class => User::fromJson(self::user($overrides)),
        };
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function attachment(array $overrides = []): array
    {
        // derived from DTO fields, not a captured Zammad response
        return array_replace([
            'id' => 4,
            'size' => 37055,
            'filename' => 'example.txt',
            'preferences' => ['Content-Type' => 'text/plain'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function objectAttribute(array $overrides = []): array
    {
        // derived from DTO fields, not a captured Zammad response
        return array_replace([
            'id' => 5,
            'name' => 'customer_reference',
            'object_lookup_id' => 1,
            'display' => 'Customer reference',
            'data_type' => 'input',
            'position' => 1,
            'data_option' => [],
            'data_option_new' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function user(array $overrides = []): array
    {
        // derived from DTO fields, not a captured Zammad response
        return array_replace([
            'id' => 1,
            'firstname' => 'Example',
            'lastname' => 'Customer',
            'login' => 'example',
            'email' => 'customer@example.test',
            'last_login' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-02T00:00:00Z',
            'created_at' => '2026-01-01T00:00:00Z',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function comment(array $overrides = []): array
    {
        // derived from DTO fields, not a captured Zammad response
        return array_replace([
            'id' => 3,
            'type_id' => 1,
            'ticket_id' => 2,
            'sender_id' => 1,
            'sender' => 'Customer',
            'subject' => null,
            'body' => 'Message',
            'content_type' => 'text/html',
            'from' => 'customer@example.test',
            'to' => null,
            'internal' => false,
            'created_by_id' => 1,
            'updated_by_id' => 1,
            'origin_by_id' => null,
            'attachments' => [],
            'updated_at' => '2026-01-02T00:00:00Z',
            'created_at' => '2026-01-01T00:00:00Z',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function ticket(array $overrides = []): array
    {
        // derived from DTO fields, not a captured Zammad response
        return array_replace([
            'id' => 2,
            'number' => 10002,
            'customer_id' => 1,
            'group_id' => 1,
            'state_id' => 2,
            'title' => 'Example ticket',
            'article_count' => 0,
            'updated_at' => '2026-01-02T00:00:00Z',
            'created_at' => '2026-01-01T00:00:00Z',
        ], $overrides);
    }
}
