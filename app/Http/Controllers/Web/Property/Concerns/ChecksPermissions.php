<?php

namespace App\Http\Controllers\Web\Property\Concerns;

use App\Domain\Access\AccessService;

/** Tells a page which actions the signed-in user may take, so buttons can be hidden. */
trait ChecksPermissions
{
    /**
     * @param  array<string, string>  $map  ability name on the page => permission key
     * @return array<string, bool>
     */
    protected function can(array $map): array
    {
        $user = request()->user();
        $access = app(AccessService::class);

        return array_map(fn (string $permission) => $user !== null && $access->allows($user, $permission), $map);
    }
}
