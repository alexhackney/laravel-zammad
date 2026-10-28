<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Traits;

trait HasExpand
{
    public bool $expand = false;

    public function expand(bool $expand = true): self
    {
        $this->expand = $expand;

        return $this;
    }
}
