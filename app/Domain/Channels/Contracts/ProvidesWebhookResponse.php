<?php

namespace App\Domain\Channels\Contracts;

use App\Models\ChannelConnection;

/** A channel that expects its own answer format (and status code) to a pushed booking. */
interface ProvidesWebhookResponse
{
    /**
     * @param  list<array{external_ref: string, status: string, booking_ref: ?string, error: ?string}>  $results
     * @return array{0: array<string, mixed>, 1: int} JSON body and HTTP status
     */
    public function webhookResponse(ChannelConnection $connection, array $results): array;
}
