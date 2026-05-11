<?php

namespace Kompo\Auth\Models\Teams\Roles;

/**
 * Thrown when a user attempts to save a model with a dirty sensible column
 * (or relationship) they don't have permission for. Issued by
 * `WriteSecurityService::validateDirtyProtectedFields`. Translated to 403 by
 * the parent `HttpException`.
 */
class InsufficientFieldPermissionException extends PermissionException
{
    public readonly string $modelClass;
    public readonly string $protectedColumn;
    public readonly string $protectionKey;

    public function __construct(
        string $modelClass,
        string $protectedColumn,
        string $protectionKey,
        $type = null,
        array $teamsIds = [],
    ) {
        $this->modelClass = $modelClass;
        $this->protectedColumn = $protectedColumn;
        $this->protectionKey = $protectionKey;

        $message = sprintf(
            'You do not have permission to modify "%s" on %s (requires "%s").',
            $protectedColumn,
            class_basename($modelClass),
            $protectionKey,
        );

        parent::__construct($message, $protectionKey, $type, $teamsIds);
    }
}
