<?php

namespace App\Console\Commands;

use App\Domain\Channels\ChannelRegistry;
use App\Domain\Channels\ChannelReservationService;
use App\Domain\Channels\ChannelSyncService;
use App\Models\ChannelConnection;
use Illuminate\Console\Command;
use Throwable;

/** Every minute: sends pending availability / rate / restriction changes and fetches bookings of polled channels. */
class ChannelsSync extends Command
{
    protected $signature = 'channels:sync {--full : Full sync of every active connection}';

    protected $description = 'Sync channel connections (outbound changes, inbound bookings of polled channels)';

    public function handle(ChannelSyncService $sync, ChannelRegistry $registry, ChannelReservationService $bookings): int
    {
        if ($this->option('full')) {
            ChannelConnection::acrossProperties()->whereIn('status', ['active', 'error'])->get()
                ->each(fn (ChannelConnection $c) => $registry->available($c->provider) && $sync->sync($c, true));
        }
        $sent = $sync->syncDue();
        $pulled = 0;
        foreach (ChannelConnection::acrossProperties()->whereIn('status', ['active', 'error'])->get() as $c) {
            if (! $registry->available($c->provider)) {
                continue;
            }
            try {
                foreach ($registry->provider($c)->pullReservations($c) as $b) {
                    $bookings->ingest($c, $b);
                    $pulled++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->line("synced={$sent} bookings={$pulled}");

        return self::SUCCESS;
    }
}
