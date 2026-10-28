<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Resources;

use AlexHackney\Zammad\Classes\RequestClass;
use AlexHackney\Zammad\Data\Ticket;
use AlexHackney\Zammad\Facades\Zammad;
use AlexHackney\Zammad\Requests\Tickets\AllTicketsRequest;
use AlexHackney\Zammad\Requests\Tickets\CreateTicketRequest;
use AlexHackney\Zammad\Requests\Tickets\DestroyTicketRequest;
use AlexHackney\Zammad\Requests\Tickets\GetTicketRequest;
use AlexHackney\Zammad\Requests\Tickets\SearchTicketRequest;
use AlexHackney\Zammad\Traits\HasExpand;
use AlexHackney\Zammad\Traits\HasLimit;
use AlexHackney\Zammad\Traits\HasPagination;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\RequestException;

class TicketResource extends RequestClass
{
    use HasExpand;
    use HasLimit;
    use HasPagination;

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @return Collection<int, Ticket> */
    public function list(): Collection
    {
        $response = self::request(new AllTicketsRequest(perPage: $this->perPage, page: $this->page));

        return $response->collect()->map(fn (array $ticket) => Ticket::fromJson($ticket));
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @return Collection<int, Ticket> */
    public function search(string $term): Collection
    {
        $response = self::request(new SearchTicketRequest(term: $term, limit: $this->limit, perPage: $this->perPage, page: $this->page));

        return $response->collect('assets.Ticket')
            ->map(fn (array $ticket) => Ticket::fromJson($ticket))
            ->values();
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    public function show(int $id): ?Ticket
    {
        $response = self::request(new GetTicketRequest(id: $id, expand: $this->expand));

        return $response->dtoOrFail();
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    public function showWithComments(int $id, bool $includeInternal = false): ?Ticket
    {
        $response = self::request(new GetTicketRequest(id: $id, expand: $this->expand));

        $ticket = $response->dtoOrFail();

        $ticket->comments = Zammad::comment()->showByTicket($id, $includeInternal);

        return $ticket;
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     * @throws \JsonException
     */
    /** @param array<string, mixed> $data */
    public function create(array $data): Ticket
    {
        $response = self::request(new CreateTicketRequest(payload: $data, expand: $this->expand));

        return $response->dtoOrFail();
    }

    /**
     * @throws \Throwable
     * @throws RequestException
     */
    public function delete(int $id): void
    {
        self::deleteRequest(new DestroyTicketRequest($id));
    }
}
