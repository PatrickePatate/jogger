<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Enums;

/** Lifecycle of a host that Jogger created at a cloud provider. Hosts without a template have no cloud state. */
enum CloudHostState: string
{
    case Requested = 'requested';   // server create asked for, not yet confirmed
    case Booting = 'booting';       // server exists, agent not enrolled yet
    case Online = 'online';         // agent enrolled, takes runners
    case Draining = 'draining';     // no new runners; finishing the current ones
    case Deleting = 'deleting';     // provider delete requested
    case Deleted = 'deleted';       // server gone, terminal
    case Failed = 'failed';         // never came up or could not be deleted, terminal once deleted

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Requested => [self::Booting, self::Failed, self::Deleting],
            self::Booting => [self::Online, self::Failed, self::Deleting],
            self::Online => [self::Draining, self::Deleting],
            self::Draining => [self::Deleting, self::Online],
            self::Deleting => [self::Deleted, self::Failed],
            self::Failed => [self::Deleting, self::Deleted],
            self::Deleted => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /**
     * States from which this one may be entered.
     *
     * @return list<self>
     */
    public function previous(): array
    {
        return array_values(array_filter(self::cases(), fn (self $from) => $from->canTransitionTo($this)));
    }

    public function isTerminal(): bool
    {
        return $this === self::Deleted || $this === self::Failed;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
