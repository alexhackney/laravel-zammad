<?php

declare(strict_types=1);

use AlexHackney\Zammad\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Saloon\Config;

uses(TestCase::class)->in(__DIR__);

uses()->beforeEach(function () {
    // Any request that is not covered by a MockClient fails the test instead of leaving the machine.
    Config::preventStrayRequests();
    Event::fake();
})->in(__DIR__);
