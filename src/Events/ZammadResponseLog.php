<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Saloon\Http\Response;

/**
 * The response's pending request carries the Authorization header.
 * Listeners must not log the whole response.
 */
class ZammadResponseLog
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Response $response) {}
}
