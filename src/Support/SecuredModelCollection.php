<?php

namespace Kompo\Auth\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Plugins\Services\SecurityServiceFactory;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Models\Teams\Team;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Tests\Stubs\TestSecuredModel;

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
     * Create a new collection.
     *
     * @param  mixed  $items
     * @return void
     */
    public function __construct($items = [])
    {
        parent::__construct($items);

        // Auto-batch load permissions if enabled and we have models
        if (static::$autoBatchEnabled && $this->isNotEmpty()) {
            $this->autoBatchLoadPermissions();
        }
    }

    /**
     * Automatically batch load field protection permissions for all models
     */
    protected function autoBatchLoadPermissions(): void
    {
        // Set processing flag to prevent infinite loops
        static::$processing = true;

        try {
            $securityFactory = app(SecurityServiceFactory::class);

            if ($this->count() == 1) {
                $model = $this->first();
                $fieldProtectionService = $securityFactory->createFieldProtectionService(get_class($model));

                $fieldProtectionService->processFieldProtection($model, class_basename(get_class($model)));

                // We must keep the same index => value
                $key = $this->keys()->first();
                $this->items = [$key => $model];
            }
            
            // Only batch if we have multiple models (optimization)
            if ($this->count() > 1) {
                $batchService = $securityFactory->createBatchPermissionServiceForModel(get_class($this->first()));

                $this->items = $batchService->batchLoadFieldProtectionPermissions($this->all());
            }
        } catch (\Throwable $e) {
            // Log but don't break - field protection will fall back to individual checks
            Log::warning('Auto-batch field protection loading failed', [
                'collection_count' => $this->count(),
                'first_model_class' => $this->first() ? $this->first() : null,
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
