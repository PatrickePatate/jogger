<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['grantable_type', 'grantable_id'])]
class TeamGrant extends Model
{
    use HasUuid7;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
