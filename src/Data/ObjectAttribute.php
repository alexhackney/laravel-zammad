<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Data;

use Illuminate\Support\Str;

class ObjectAttribute
{
    use NormalizesJsonScalars;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromJson(array $data): self
    {
        return new self(
            id: self::jsonInt($data['id']),
            name: self::jsonString($data['name']),
            object_lookup_id: array_key_exists('object_lookup_id', $data)
                ? ($data['object_lookup_id'] === null ? null : self::jsonInt($data['object_lookup_id']))
                : null,
            display: self::jsonString($data['display']),
            data_type: self::jsonString($data['data_type']),
            position: self::jsonInt($data['position']),
            data_option: $data['data_option'],
            data_option_new: $data['data_option_new'],
        );
    }

    /**
     * @param  array<string, mixed>  $data_option
     * @param  array<string, mixed>|null  $data_option_new
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?int $object_lookup_id,
        public string $display,
        public string $data_type,
        public int $position,
        public array $data_option,
        public ?array $data_option_new,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data_option
     * @param  array<string, mixed>|null  $data_option_new
     */
    public static function fake(
        ?int $id = null,
        ?string $name = null,
        ?int $object_lookup_id = null,
        ?string $display = null,
        ?string $data_type = null,
        ?int $position = null,
        ?array $data_option = null,
        ?array $data_option_new = null,
    ): self {
        return new self(
            id: $id ?? random_int(1, 1000),
            name: $name ?? 'sample_boolean',
            object_lookup_id: $object_lookup_id ?? random_int(1, 10),
            display: $display ?? 'Sample Boolean',
            data_type: $data_type ?? 'boolean',
            position: $position ?? random_int(1, 1000),
            data_option: $data_option ?? ['options' => ['true' => 'yes', 'false' => 'no'], 'default' => 'false'],
            data_option_new: $data_option_new ?? [],
        );
    }

    /**
     * @param  array<string, mixed>|null  $data_options
     * @return array{
     *     name: string, object: string|null, display: string|null, active: bool|null,
     *     position: int|null, data_type: string|null, data_option: array<string, mixed>|null
     * }
     */
    public static function fakeCreateToArray(
        ?string $name = 'sample_object',
        ?string $object = 'Ticket',
        ?string $display = 'Sample Object',
        ?bool $active = true,
        ?int $position = null,
        ?string $data_type = 'select',
        ?array $data_options = [
            'options' => [
                'key-one' => 'First Key',
                'key-two' => 'Second Key',
                'key-three' => 'Third Key',
            ],
            'default' => 'key-one',
        ]
    ): array {
        return [
            'name' => $name.'_'.Str::slug(Str::orderedUuid()->toString(), '_'),
            'object' => $object,
            'display' => $display,
            'active' => $active,
            'position' => $position,
            'data_type' => $data_type,
            'data_option' => $data_options,
        ];
    }
}
