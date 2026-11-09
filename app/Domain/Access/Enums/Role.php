<?php

declare(strict_types=1);

namespace App\Domain\Access\Enums;

enum Role: string
{
    case Viewer = 'viewer';
    case Operator = 'operator';
    case Admin = 'admin';

    public function level(): int
    {
        return match ($this) {
            self::Viewer => 1,
            self::Operator => 2,
            self::Admin => 3,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->level() >= $other->level();
    }

    /**
     * Highest role in a collection of roles or role values; null when empty.
     *
     * @param  iterable<self|string>  $roles
     */
    public static function highest(iterable $roles): ?self
    {
        $best = null;

        foreach ($roles as $role) {
            $role = $role instanceof self ? $role : self::from($role);

            if ($best === null || $role->level() > $best->level()) {
                $best = $role;
            }
        }

        return $best;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
