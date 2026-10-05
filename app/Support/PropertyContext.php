<?php

namespace App\Support;

use App\Models\Property;
use App\Models\PropertyUser;
use RuntimeException;

/**
 * Holds the property the current request is working on.
 * Bound as a scoped singleton, so it is reset for every request and queued job.
 */
class PropertyContext
{
    private ?Property $property = null;

    private ?PropertyUser $membership = null;

    private bool $supportMode = false;

    public function set(Property $property, ?PropertyUser $membership, bool $supportMode = false): void
    {
        $this->property = $property;
        $this->membership = $membership;
        $this->supportMode = $supportMode;
    }

    public function clear(): void
    {
        $this->property = null;
        $this->membership = null;
        $this->supportMode = false;
    }

    public function has(): bool
    {
        return $this->property !== null;
    }

    public function property(): Property
    {
        if ($this->property === null) {
            throw new RuntimeException('No property selected for this request.');
        }

        return $this->property;
    }

    public function id(): int
    {
        return $this->property()->id;
    }

    public function idOrNull(): ?int
    {
        return $this->property?->id;
    }

    public function membership(): ?PropertyUser
    {
        return $this->membership;
    }

    /** True when an E2X platform user is viewing a property they are not a member of. */
    public function isSupportMode(): bool
    {
        return $this->supportMode;
    }
}
