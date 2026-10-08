<?php

namespace App\Domain\Channels;

use App\Models\ApprovalRequest;
use App\Models\ChannelConnection;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Applies the outcome of a channel connection approval. */
class ConnectionApprovalService
{
    public function __construct(
        private readonly ChannelRegistry $registry,
        private readonly ConnectionService $connections,
        private readonly \App\Domain\Audit\AuditLogger $audit,
    ) {}

    public function apply(ApprovalRequest $request, bool $approve, User $by): void
    {
        $connection = ChannelConnection::acrossProperties()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();

        if (! $approve) {
            $connection->forceFill(['approval_status' => 'rejected', 'approved_by' => null, 'approved_at' => null])->save();
            $this->audit->log('channel.rejected', $connection, ['provider' => $connection->provider], $connection->property_id, $by->id);

            return;
        }

        // E2X enters the credentials before approving; without them nothing could be sent.
        $stored = (array) ($connection->credentials ?? []);
        foreach ($this->registry->provider($connection)->credentialFields() as $field) {
            if (! empty($field['required']) && empty($stored[$field['key']])) {
                throw ValidationException::withMessages(['request' => __('approvals.credentials_missing')]);
            }
        }

        $connection->forceFill(['approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'approval_note' => null])->save();
        $this->audit->log('channel.approved', $connection, ['provider' => $connection->provider], $connection->property_id, $by->id);
        $this->connections->test($connection->fresh(), $by);
    }
}
