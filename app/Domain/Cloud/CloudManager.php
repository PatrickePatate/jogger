<?php

declare(strict_types=1);

namespace App\Domain\Cloud;

use App\Domain\Cloud\Contracts\CloudDriver;
use App\Domain\Cloud\Models\CloudProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/** Resolves the driver for a provider from the `jogger.cloud.drivers` map. */
final class CloudManager
{
    public function __construct(private readonly Container $container) {}

    /** @throws InvalidArgumentException for a name not in the drivers map */
    public function driver(string $name): CloudDriver
    {
        $class = $this->classes()[$name] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("Unknown cloud driver [{$name}].");
        }

        $driver = $this->container->make($class);
        if (! $driver instanceof CloudDriver) {
            throw new InvalidArgumentException("Cloud driver [{$name}] does not implement ".CloudDriver::class.'.');
        }

        return $driver;
    }

    public function for(CloudProvider $provider): CloudDriver
    {
        return $this->driver($provider->driver);
    }

    /** @return list<string> */
    public function drivers(): array
    {
        return array_map(strval(...), array_keys($this->classes()));
    }

    /** @return array<string, string> */
    private function classes(): array
    {
        return (array) config('jogger.cloud.drivers', []);
    }
}
