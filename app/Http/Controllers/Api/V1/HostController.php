<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Http\Resources\HostResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class HostController
{
    /** Hosts are shared infrastructure: every token may list them. Revoked hosts are left out. */
    public function index(): AnonymousResourceCollection
    {
        return HostResource::collection(Host::query()->where('status', '!=', HostStatus::Revoked->value)->orderBy('name')->get());
    }
}
