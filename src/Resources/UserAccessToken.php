<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Resources;

use AlexHackney\Zammad\Classes\RequestClass;
use AlexHackney\Zammad\Requests\AccessTokens\AllAccessTokensRequest;
use AlexHackney\Zammad\Requests\AccessTokens\CreateAccessTokenRequest;
use AlexHackney\Zammad\Requests\AccessTokens\DestroyAccessTokenRequest;
use Saloon\Exceptions\Request\RequestException;

class UserAccessToken extends RequestClass
{
    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @return array<string, mixed> */
    public function list(): array
    {
        $response = self::request(new AllAccessTokensRequest);

        return $response->json();
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $response = self::request(new CreateAccessTokenRequest($data));

        return $response->json();
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @return array<string, mixed> */
    public function delete(int|string $id): array
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            throw new \InvalidArgumentException('The access token id must be a positive integer.');
        }

        $response = self::deleteRequest(new DestroyAccessTokenRequest($id));

        return $response->json();
    }
}
