<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Tests\Feature\Support;

use AlexHackney\Zammad\Tests\Unit\Data\DtoFixtures;

require_once __DIR__.'/../../Unit/Data/DtoFixtures.php';

final class Mocks
{
    public static function attachment(array $overrides = []): array
    {
        // derived from DTO fields, not a captured Zammad response
        return array_replace([
            'id' => 42,
            'size' => 30,
            'filename' => 'test.txt',
            'preferences' => [
                'Content-Type' => 'text/plain',
                'Mime-Type' => 'text/plain',
            ],
        ], $overrides);
    }

    public static function comment(array $overrides = []): array
    {
        // derived from DTO fields, not a captured Zammad response
        return DtoFixtures::comment($overrides);
    }

    public static function ticket(array $overrides = []): array
    {
        // derived from DTO fields, not a captured Zammad response
        return DtoFixtures::ticket($overrides);
    }

    public static function objectAttribute(array $overrides = []): array
    {
        // derived from DTO fields, not a captured Zammad response
        return array_replace([
            'id' => 10,
            'name' => 'sample_object',
            'object_lookup_id' => 1,
            'display' => 'Sample Object',
            'data_type' => 'select',
            'position' => 1500,
            'data_option' => [
                'options' => [
                    'key-one' => 'First Key',
                    'key-two' => 'Second Key',
                    'key-three' => 'Third Key',
                ],
                'default' => 'key-one',
            ],
            'data_option_new' => [],
        ], $overrides);
    }

    public static function user(array $overrides = []): array
    {
        // derived from DTO fields, not a captured Zammad response
        return array_replace([
            'id' => 5,
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'login' => 'max.mustermann@example.test',
            'email' => 'max.mustermann@example.test',
            'last_login' => '2026-01-01T10:00:00Z',
            'updated_at' => '2026-01-02T10:00:00Z',
            'created_at' => '2026-01-01T09:00:00Z',
        ], $overrides);
    }

    public static function accessToken(array $overrides = []): array
    {
        // derived from DTO fields, not a captured Zammad response
        return array_replace([
            'id' => 7,
            'name' => 'test-access-token',
        ], $overrides);
    }
}
