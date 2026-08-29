<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

trait RoleRequestsUtils
{
    public function getRoleForm($id = null)
    {
        abort_unless(auth()->user()->hasPermission('Role', PermissionTypeEnum::WRITE), 403);

        return new (config('kompo-auth.role-form-namespace'))($id);
    }

    public function getEditSectionInfoForm()
    {
        $sectionId = request('section_id') ?: abort(400);

        return new EditPermissionSectionInfo($sectionId, ['refresh_id' => RolesAndPermissionMatrix::ID]);
    }

    public function getPermissionInfoModal()
    {
        return new PermissionInfoModal(request('permission_id'), ['refresh_id' => RolesAndPermissionMatrix::ID]);
    }

    public function getPendingActionsToDeleteRoleModal()
    {
        return new PendingActionsToDeleteRoleModal(request('id'));
    }

    public function deleteRole()
    {
        abort_unless(auth()->user()->hasPermission('Role', PermissionTypeEnum::WRITE), 403);

        $role = RoleModel::findOrFail(request('id'));
        abort_unless($role->canSeeDeletedButton(), 403);
        abort_if($role->hasPendingActionsToDelete(), 422, __('auth-you-cannot-delete-role-with-team-roles'));

        $role->delete();
        RolesMatrixView::forgetRole($role->id);

        return response()->kompoMulti([
            response()->closeModal(),
            response()->kompoRefresh(RolesAndPermissionMatrix::ID),
        ]);
    }
}
