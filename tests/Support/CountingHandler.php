<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Github\Contracts\WebhookHandler;
use App\Domain\Github\Models\WebhookDelivery;
use Throwable;

/** Test webhook handler: counts invocations and can be told to throw. */
final class CountingHandler implements WebhookHandler
{
    public static int $calls = 0;

    public static ?Throwable $throw = null;

    /** @var array<int, string> */
    public static array $seen = [];

    public static function reset(): void
    {
        self::$calls = 0;
        self::$throw = null;
        self::$seen = [];
    }

    public function handle(WebhookDelivery $delivery): void
    {
        self::$calls++;
        self::$seen[] = $delivery->delivery_id;

        if (self::$throw !== null) {
            throw self::$throw;
        }
    }
}
