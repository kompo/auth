<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Form;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

class RolesManager extends Form
{
    use RoleRequestsUtils;

    public $id = 'roles-manager';

    public $class = 'pb-16';
    public $containerClass = 'container-fluid';

    public function render()
    {
        return _Flex(
            $this->rolesExplanationEl(),

            _Rows(
                _FlexBetween(
                    _Html('Roles')->class('text-lg font-bold'),
                    _Link('Create Role')->selfGet('getRoleForm')->inModal(),
                ),

                new RolesAndPermissionMatrix(),
            )->class('pr-8 md:pr-30')
        )->class('gap-8 px-4 !items-start');
    }

    protected function rolesExplanationEl()
    {
        return _Rows(
            _Html('Roles')->class('text-lg font-bold opacity-0'),             // Just as a placeholder to align with the right side
            _CardWhite(
                _Html('permission-levels-explained')->class('font-semibold mb-4'),

                _Rows(collect(PermissionTypeEnum::cases())->filter(fn($case) => $case->visibleInSelects())->map(function ($case, $i) {
                    return _Collapsible(_Html($case->explanation())->class('text-sm'))
                        ->expandedByDefault()
                        ->titleLabel(
                            _Flex(
                                _Html($case->label())->class('text-gray-700'),
                                _Html()->class('rounded h-4 w-4 border border-black')->class($case->color()),
                            )->class('gap-3 w-[90%]')
                        );
                }))->class('gap-5'),

                _Html('permission-priority-order')->class('font-semibold mt-6 mb-2'),
                _Html('permission-roles-are-prioritized-by-order-explained')->class('text-sm'),
            )->class('h-full p-8'),
        )->class('h-full w-[350px] mt-6');
    }

    public function js()
    {
        $spinnerHtml = _Spinner()->__toHtml();

        $rolesJs = file_get_contents(__DIR__ . '/../../../resources/js/roles-manager.js');

        return str_replace('$spinnerHtml', $spinnerHtml, $rolesJs);
    }
}
