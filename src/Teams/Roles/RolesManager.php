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
                    _Link('Create Role')->selfGet('getRoleForm')->inModal()
                        ->checkAuthWrite('Role'),
                ),

                new RolesAndPermissionMatrix(),
            )->class('pr-8 md:pr-30')
        )->class('gap-8 px-4 !items-start');
    }

    protected function rolesExplanationEl()
    {
        return _Flex(
            // Collapsible content. Width transitions from 350px → 0 when the
            // toggle button is clicked. overflow-hidden + white-space:nowrap
            // on the inner content keeps the slide animation crisp.
            _Rows(
                _Html('Roles')->class('text-lg font-bold opacity-0'),
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
                )->class('p-8'),
            )
            ->id('legend-content')
            ->class('w-[350px] overflow-hidden transition-all duration-300 ease-in-out'),

            // Full-height side toggle button. Click rotates the chevron and
            // toggles `!w-0` on the legend, sliding it closed/open horizontally.
            _FlexCenter(
                _Html()->icon('icon-up')->id('legend-toggle-icon')
                    ->class('text-xl transition-transform duration-300 ease-in-out')
                    ->style('transform: rotate(-90deg)'),
            )
            ->onClick(fn($e) => $e->run('() => {
                const $content = $("#legend-content");
                const $icon = $("#legend-toggle-icon");
                const collapsed = $content.toggleClass("!w-0").hasClass("!w-0");
                $icon.css("transform", collapsed ? "rotate(90deg)" : "rotate(-90deg)");
            }'))
            ->style('max-height: 550px;')
            ->class('cursor-pointer self-stretch w-6 bg-white hover:bg-level2 transition-colors rounded-r-lg mt-8 mb-4'),
        )->class('mt-6 !items-start');
    }

    public function js()
    {
        $spinnerHtml = _Spinner()->__toHtml();

        $rolesJs = file_get_contents(__DIR__ . '/../../../resources/js/roles-manager.js');

        return str_replace('$spinnerHtml', $spinnerHtml, $rolesJs);
    }
}
