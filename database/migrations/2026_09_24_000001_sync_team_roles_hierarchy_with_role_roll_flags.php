<?php

use Illuminate\Database\Migrations\Migration;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;

return new class extends Migration {
    public function up(): void
    {
        // Assignments kept the hierarchy their role had when assigned, so later roll-flag toggles left them out of sync.
        RoleModel::withoutGlobalScopes()->get()->each->syncTeamRolesHierarchyToRollFlags();

        app(PermissionCacheInvalidator::class)->rolePermissionsChanged([]);
    }

    public function down(): void
    {
        // Data fix, previous hierarchies are not recoverable.
    }
};
