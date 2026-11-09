<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;

final class CreateTeam
{
    public function __invoke(User $actor, string $name): Team
    {
        $name = trim($name);
        $base = Str::slug($name) ?: 'team';
        $slug = $base;

        for ($i = 2; Team::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        $team = Team::create(['name' => $name, 'slug' => $slug]);

        Audit::record('team.created', $team, ['name' => $name], $actor);

        return $team;
    }
}
