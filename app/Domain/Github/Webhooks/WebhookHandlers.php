<?php

declare(strict_types=1);

namespace App\Domain\Github\Webhooks;

use App\Domain\Github\Contracts\WebhookHandler;
use InvalidArgumentException;

/** Event name → handler class. Later tasks register theirs in AppServiceProvider; events without one are marked "ignored". */
final class WebhookHandlers
{
    /** @var array<string, class-string<WebhookHandler>> */
    private array $map = [];

    /**
     * @param  class-string<WebhookHandler>  $handler
     */
    public function register(string $event, string $handler): void
    {
        if (! is_subclass_of($handler, WebhookHandler::class)) {
            throw new InvalidArgumentException("{$handler} must implement ".WebhookHandler::class);
        }

        $this->map[$event] = $handler;
    }

    public function for(string $event): ?WebhookHandler
    {
        return isset($this->map[$event]) ? app($this->map[$event]) : null;
    }

    /**
     * @return array<string, class-string<WebhookHandler>>
     */
    public function all(): array
    {
        return $this->map;
    }
}
