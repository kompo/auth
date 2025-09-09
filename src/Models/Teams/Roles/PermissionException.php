<?php

namespace Kompo\Auth\Models\Teams\Roles;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PermissionException extends HttpException
{
    public function __construct($message = 'You do not have permission to perform this action.', $permissionKey = null, $type = null, $teamsIds = [])
    {
        if ($permissionKey) {
            Log::info('Denied permission', [
                'permission' => $permissionKey,
                'type' => $type?->label(),
                'teams' => $teamsIds,
                'backtrace' => collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))->map(fn($item) => $item['function'] ?? null)->filter()->values(),
            ]);
        }

        parent::__construct(403, $message);
    }
}