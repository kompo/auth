<?php

namespace Kompo\Auth\Models\Plugins\Services;

/**
 * Per-instance security state, stored directly on the model.
 *
 * Replaces all the static arrays that previously tracked per-instance data
 * ($blockedRelationshipsRegistry, $fieldProtectionInProgress, $bypassedModels).
 *
 * Key advantage: no getModelKey() calls needed — state is ON the model instance.
 * Garbage collected naturally when the model is released.
 */
class ModelSecurityState
{
    /**
     * Whether full protection has been resolved for this instance
     * (batch processing or lazy resolution completed).
     */
    public bool $protectionResolved = false;

    /**
     * Relationships blocked for this instance.
     * Set by batch processing or lazy resolution.
     */
    public array $blockedRelationships = [];

    /**
     * Whether this instance bypasses security (owner, flag, etc.).
     * null = not yet checked, true = bypassed, false = not bypassed.
     */
    public ?bool $bypassed = null;

    /**
     * Prevent reentrant field protection processing.
     */
    public bool $processing = false;

    /**
     * Check if a specific relationship is blocked for this instance. O(1) via isset.
     */
    public function isRelationBlocked(string $relation): bool
    {
        return isset($this->blockedRelationships[$relation]);
    }

    /**
     * Block a set of relationships for this instance.
     */
    public function blockRelationships(array $relations): void
    {
        foreach ($relations as $relation) {
            $this->blockedRelationships[$relation] = true;
        }
    }
}
