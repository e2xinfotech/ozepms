<?php

namespace App\Http\Resources\Calendar;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Outcome of a calendar edit: how many nights changed and what was left alone (with a reason). */
class AriChangeResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        $r = $this->resource;
        $changed = $r['inventory_rows'] + $r['ari_rows'] + $r['occupancy_rows'];

        return [
            'changed' => $changed,
            'inventory_rows' => $r['inventory_rows'],
            'ari_rows' => $r['ari_rows'],
            'occupancy_rows' => $r['occupancy_rows'],
            'skipped' => $r['skipped'],
        ];
    }
}
