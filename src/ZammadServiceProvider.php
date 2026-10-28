<?php

declare(strict_types=1);

namespace AlexHackney\Zammad;

use AlexHackney\Zammad\Commands\PingCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ZammadServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-zammad')
            ->hasConfigFile()
            ->hasCommand(PingCommand::class);
    }
}
