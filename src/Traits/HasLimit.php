<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Traits;

trait HasLimit
{
    public ?int $limit = null;

    public function limit(int $limit = 1): self
    {
        $this->limit = $limit;

        return $this;
    }
}
