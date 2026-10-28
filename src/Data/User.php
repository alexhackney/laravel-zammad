<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Data;

use Carbon\Carbon;

class User
{
    use NormalizesJsonScalars;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromJson(array $data, bool $expanded = false): self
    {
        return new self(
            id: self::jsonInt($data['id']),
            first_name: self::jsonString($data['firstname']),
            last_name: self::jsonString($data['lastname']),
            login: self::jsonString($data['login']),
            email: self::jsonString($data['email']),
            last_login_at: Carbon::parse($data['last_login']),
            updated_at: Carbon::parse($data['updated_at']),
            created_at: Carbon::parse($data['created_at']),
            expanded: $expanded ? $data : null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $expanded
     */
    public function __construct(
        public int $id,
        public string $first_name,
        public string $last_name,
        public string $login,
        public string $email,
        public Carbon $last_login_at,
        public Carbon $updated_at,
        public Carbon $created_at,
        public ?array $expanded = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $expanded
     */
    public static function fake(
        ?int $id = null,
        ?string $first_name = null,
        ?string $last_name = null,
        ?string $login = null,
        ?string $email = null,
        ?Carbon $last_login_at = null,
        ?Carbon $updated_at = null,
        ?Carbon $created_at = null,
        ?array $expanded = null,
    ): self {
        return new self(
            id: $id ?? random_int(1, 1000),
            first_name: $first_name ?? 'Max',
            last_name: $last_name ?? 'Mustermann',
            login: $login ?? 'max.mustermann@example.test',
            email: $email ?? 'max.mustermann@example.test',
            last_login_at: $last_login_at ?? now(),
            updated_at: $updated_at ?? now(),
            created_at: $created_at ?? now()->subDay(),
            expanded: $expanded ?? null,
        );
    }
}
