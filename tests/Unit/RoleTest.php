<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Access\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    /**
     * @return array<string, array{0: Role, 1: Role, 2: bool}>
     */
    public static function matrix(): array
    {
        return [
            'viewer >= viewer' => [Role::Viewer, Role::Viewer, true],
            'viewer >= operator' => [Role::Viewer, Role::Operator, false],
            'viewer >= admin' => [Role::Viewer, Role::Admin, false],
            'operator >= viewer' => [Role::Operator, Role::Viewer, true],
            'operator >= operator' => [Role::Operator, Role::Operator, true],
            'operator >= admin' => [Role::Operator, Role::Admin, false],
            'admin >= viewer' => [Role::Admin, Role::Viewer, true],
            'admin >= operator' => [Role::Admin, Role::Operator, true],
            'admin >= admin' => [Role::Admin, Role::Admin, true],
        ];
    }

    #[DataProvider('matrix')]
    public function test_at_least(Role $have, Role $need, bool $expected): void
    {
        $this->assertSame($expected, $have->atLeast($need));
    }

    public function test_highest_picks_the_most_privileged_and_accepts_strings(): void
    {
        $this->assertSame(Role::Operator, Role::highest(['viewer', Role::Operator, 'viewer']));
        $this->assertSame(Role::Admin, Role::highest(['operator', 'admin']));
        $this->assertNull(Role::highest([]));
    }

    public function test_values_match_the_database_check_constraint(): void
    {
        $this->assertSame(['viewer', 'operator', 'admin'], array_map(fn (Role $r) => $r->value, Role::cases()));
    }
}
