<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Resources;

use AlexHackney\Zammad\Classes\RequestClass;
use AlexHackney\Zammad\Data\ObjectAttribute;
use AlexHackney\Zammad\Requests\ObjectAttribute\AllObjectAttributesRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\CreateObjectAttributeRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\DestroyObjectAttributeRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\ExecuteMigrationsObjectAttributeRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\GetObjectAttributeRequest;
use AlexHackney\Zammad\Requests\ObjectAttribute\UpdateObjectAttributeRequest;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\RequestException;

class ObjectAttributeResource extends RequestClass
{
    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @return Collection<int, ObjectAttribute> */
    public function list(): Collection
    {
        $response = self::request(new AllObjectAttributesRequest);

        return $response->collect()->map(fn (array $object) => ObjectAttribute::fromJson($object));
    }

    public function show(int $id): ?ObjectAttribute
    {
        $response = self::request(new GetObjectAttributeRequest($id));

        return $response->dtoOrFail();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): ObjectAttribute
    {
        $response = self::request(new CreateObjectAttributeRequest($data));

        return $response->dtoOrFail();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): ObjectAttribute
    {
        $response = self::request(new UpdateObjectAttributeRequest($id, $data));

        return $response->dtoOrFail();
    }

    public function delete(int $id): void
    {
        self::deleteRequest(new DestroyObjectAttributeRequest($id));
    }

    public function executeMigrations(): void
    {
        self::request(new ExecuteMigrationsObjectAttributeRequest);
    }
}
