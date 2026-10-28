<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Resources;

use AlexHackney\Zammad\Classes\RequestClass;
use AlexHackney\Zammad\Data\User;
use AlexHackney\Zammad\Requests\Users\AllUsersRequest;
use AlexHackney\Zammad\Requests\Users\CreateUserRequest;
use AlexHackney\Zammad\Requests\Users\DestroyUserRequest;
use AlexHackney\Zammad\Requests\Users\GetUserRequest;
use AlexHackney\Zammad\Requests\Users\SearchUserRequest;
use AlexHackney\Zammad\Requests\Users\UpdateUserRequest;
use AlexHackney\Zammad\Traits\HasExpand;
use AlexHackney\Zammad\Traits\HasLimit;
use AlexHackney\Zammad\Traits\HasPagination;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\RequestException;

class UserResource extends RequestClass
{
    use HasExpand;
    use HasLimit;
    use HasPagination;

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    public function me(): User
    {
        $response = self::request(new GetUserRequest(expand: $this->expand));

        return $response->dtoOrFail();
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @return Collection<int, User> */
    public function list(): Collection
    {
        $response = self::request(new AllUsersRequest(perPage: $this->perPage, page: $this->page));

        return $response->collect()->map(fn (array $user) => User::fromJson($user));
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @return null|User|Collection<int, User> */
    public function search(string $term): null|User|Collection
    {
        $limit = $this->limit !== null && $this->limit > 1 ? $this->limit : 1;
        $response = self::request(new SearchUserRequest(term: $term, limit: $limit));

        $data = $response->json();

        if ($this->limit > 1) {
            return $response->collect()->map(fn (array $user) => User::fromJson($user));
        }

        return Arr::exists($data, 0)
            ? User::fromJson($data[0])
            : null;
    }

    /**
     * Use me() for the current user.
     *
     * @throws \InvalidArgumentException When $id is below 1
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    public function show(int $id): User
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('The user id must be a positive integer. Use me() for the current user.');
        }

        $response = self::request(new GetUserRequest(id: $id, expand: $this->expand));

        return $response->dtoOrFail();
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @param array<string, mixed> $data */
    public function create(array $data): User
    {
        $response = self::request(new CreateUserRequest(payload: $data, expand: $this->expand));

        return $response->dtoOrFail();
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @param array<string, mixed> $data */
    public function update(int|string $id, array $data): User
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            throw new \InvalidArgumentException('The user id must be a positive integer.');
        }

        $response = self::request(new UpdateUserRequest(id: $id, payload: $data, expand: $this->expand));

        return $response->dtoOrFail();
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     */
    public function delete(int $id): void
    {
        self::deleteRequest(new DestroyUserRequest($id));
    }

    /**
     * Returns the user whose email equals $email (case-insensitive), or creates one.
     * On create, $data is kept and its 'email' key, if any, is replaced by $email.
     *
     * @param  array<string, mixed>  $data  Extra attributes used only when the user is created
     *
     * @throws \InvalidArgumentException When $email is empty or contains whitespace, " \ ( ) * ? or :
     * @throws \JsonException
     * @throws \Throwable
     * @throws RequestException
     */
    public function searchOrCreateByEmail(string $email, array $data = []): User
    {
        if ($email === '' || preg_match('/[\s"\\\\()*?:]/', $email)) {
            throw new \InvalidArgumentException('The email is empty or contains characters that are not allowed in a user search (whitespace, ", \\, (, ), *, ? or :).');
        }

        $this->limit = 1;

        $user = $this->search("email:{$email}");

        if ($user instanceof User && mb_strtolower(trim($user->email), 'UTF-8') === mb_strtolower(trim($email), 'UTF-8')) {
            return $user;
        }

        return $this->create(array_merge($data, ['email' => $email]));
    }
}
