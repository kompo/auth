<?php

namespace Kompo\Auth\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Kompo\Auth\Models\Plugins\Services\FieldProtectionService;
use Kompo\Auth\Models\Plugins\Services\SecurityServiceFactory;

/**
 * Secured Model Collection
 *
 * Custom collection that automatically batch loads field protection permissions
 * when created, preventing N+1 queries for sensitive field access.
 *
 * Security-first: Auto-batching is ENABLED by default
 * Opt-out: Use ->withoutBatchedFieldProtection() scope to disable
 */
class SecuredModelCollection extends Collection
{
    /**
     * Flag to control whether auto-batching should run
     */
    protected static $autoBatchEnabled = true;

    /**
     * Flag to prevent infinite loops during processing
     */
    protected static $processing = false;

    /**
     * Trigger auto-batch loading on this collection.
     *
     * Called explicitly by HasSecurity::newCollection() on the primary collection
     * produced by an Eloquent query. NOT called from __construct(), so derived
     * collections produced by Laravel's `new static(...)` in map/filter/groupBy/etc.
     * do not re-run batch loading on transformed items (Kompo elements, sub-collections,
     * etc.) — that was the source of the "Auto-batch field protection loading failed"
     * warnings.
     */
    public function autoBatch(): self
    {
        if (!static::$autoBatchEnabled || static::$processing) {
            return $this;
        }

        if ($this->isEmpty()) {
            return $this;
        }

        $first = $this->first();

        // Only batch-load when items are Eloquent models that actually expose the
        // HasSecurity per-instance state. Filters out:
        //  - non-Eloquent objects (Kompo Rows / view components)
        //  - Eloquent models without HasSecurity
        //  - nested SecuredModelCollection items (groupBy/chunk/split outputs)
        if (!$first instanceof \Illuminate\Database\Eloquent\Model
            || !method_exists($first, 'getSecurityState')) {
            return $this;
        }

        $this->autoBatchLoadPermissions($first);

        return $this;
    }

    /**
     * Automatically batch load field protection permissions for all models.
     * Caller must ensure the collection is non-empty and items are HasSecurity models.
     */
    protected function autoBatchLoadPermissions($first): void
    {
        // Set processing flag to prevent infinite loops
        static::$processing = true;

        try {
            $securityFactory = app(SecurityServiceFactory::class);
            $batchService = $securityFactory->createBatchPermissionServiceForModel(get_class($first));

            $this->items = $batchService->batchLoadFieldProtectionPermissions($this->all());

            // Clean up fieldProtectionInProgress entries that accumulated during batch processing.
            // We do NOT clear $blockedRelationshipsRegistry here because it's still needed
            // for the fast-path lookup in isBlockedRelationship() during attribute access.
            // The request-level cleanup (HasSecurity::registerRequestCleanup) handles full cleanup.
            FieldProtectionService::clearInProgressTracking();
        } catch (\Throwable $e) {
            // Log but don't break - field protection will fall back to individual checks
            Log::warning('Auto-batch field protection loading failed', [
                'collection_count' => $this->count(),
                'first_model_class' => $this->first() && is_object($this->first()) ? get_class($this->first()) : null,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // Always reset processing flag
            static::$processing = false;
        }
    }

    /**
     * Disable auto-batching for the next collection creation
     * Called by withoutBatchedFieldProtection() scope
     */
    public static function disableAutoBatching(): void
    {
        static::$autoBatchEnabled = false;
    }

    /**
     * Re-enable auto-batching (default state)
     */
    public static function enableAutoBatching(): void
    {
        static::$autoBatchEnabled = true;
    }

    /**
     * Check if auto-batching is enabled
     */
    public static function isAutoBatchingEnabled(): bool
    {
        return static::$autoBatchEnabled;
    }

    /**
     * Check if currently processing (to prevent infinite loops)
     */
    public static function isProcessing(): bool
    {
        return static::$processing;
    }
}
