<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Form;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

class RolesManager extends Form
{
    use RoleRequestsUtils;

    public $id = 'roles-manager';

    public $class = 'roles-manager pb-16';
    public $containerClass = 'container-fluid';

    protected $permissionKey = 'Role';
    protected $forcePermissionType = PermissionTypeEnum::WRITE;

    public function render()
    {
        return _Flex(
            $this->legend(),
            _Rows(
                _FlexBetween(
                    _Html('auth-roles')->class('roles-manager-title'),
                    _Link('permissions-create-role')->icon(_SaxSvg('add', 16))->class('roles-manager-create')
                        ->selfGet('getRoleForm')->inModal()->checkAuthWrite('Role'),
                )->class('mb-4 !items-end'),
                new RolesAndPermissionMatrix(),
            )->class('flex-1 min-w-0'),
        )->class('gap-6 px-4 !items-start')->style(PermissionTypeEnum::cssLabelVars());
    }

    protected function legend()
    {
        return _Flex(
            _Rows(
                _Html('auth-roles')->class('roles-manager-title opacity-0 mb-4'),
                _CardWhite($this->legendContent())->class('p-5'),
            )->id('legend-content')->class('roles-legend w-[292px] overflow-hidden transition-all duration-300 ease-in-out'),
            _FlexCenter(
                _Html()->icon('icon-up')->id('legend-toggle-icon')
                    ->class('text-xl transition-transform duration-300 ease-in-out')
                    ->style('transform: rotate(-90deg)'),
            )
            ->onClick(fn($e) => $e->run('() => {
                const collapsed = $("#legend-content").toggleClass("!w-0").hasClass("!w-0");
                $("#legend-toggle-icon").css("transform", collapsed ? "rotate(90deg)" : "rotate(-90deg)");
            }'))
            ->style('max-height: 700px')
            ->class('cursor-pointer self-stretch w-6 bg-white hover:bg-level2 transition-colors rounded-r-lg mt-16 mb-12'),
        )->class('!items-start');
    }

    protected function legendContent()
    {
        return _Rows(
            _Html('permission-levels-explained')->class('roles-legend-title'),
            $this->legendItem('perm-chip perm-none', 'permissions-permission-none', 'none'),
            $this->legendItem(PermissionTypeEnum::READ->cellClass(), PermissionTypeEnum::READ->label(), 'read'),
            $this->legendItem(PermissionTypeEnum::ALL->cellClass(), PermissionTypeEnum::ALL->label(), 'all'),
            $this->legendItem(PermissionTypeEnum::DENY->cellClass(), PermissionTypeEnum::DENY->label(), 'deny'),

            _Html('permission-priority-order')->class('roles-legend-title mt-5'),
            $this->priorityItem(1, 'permissions-state-denied', 'permissions-priority-wins')->class('is-top'),
            $this->priorityItem(2, PermissionTypeEnum::ALL->label()),
            $this->priorityItem(3, PermissionTypeEnum::READ->label()),
            $this->priorityItem(4, 'permissions-permission-none'),

            _Html('permissions-section-pill')->class('roles-legend-title mt-5'),
            $this->pillExample(SectionAggregate::sample(['all' => 7]), 'permissions-legend-uniform'),
            $this->pillExample(SectionAggregate::sample(['read' => 2, 'none' => 4]), 'permissions-legend-partial'),
            $this->pillExample(SectionAggregate::sample(['all' => 4, 'read' => 2, 'deny' => 1]), 'permissions-legend-mixed'),
            _Html('permissions-section-pill-help')->class('roles-legend-desc mt-2'),
        );
    }

    protected function legendItem(string $chipClass, string $label, string $code)
    {
        return _Rows(
            _Flex(
                _Html()->class($chipClass),
                _Html($label)->class('font-semibold text-sm'),
            )->class('gap-3'),
            _Html('permissions-legend-' . $code . '-desc')->class('roles-legend-desc'),
        )->class('roles-legend-item')->class($code === 'deny' ? 'is-deny' : '');
    }

    protected function priorityItem(int $rank, string $label, ?string $note = null)
    {
        return _Flex(
            _Html($rank)->class('roles-priority-rank'),
            _Html($label)->class('text-sm'),
            !$note ? null : _Html($note)->class('roles-priority-note'),
        )->class('roles-priority-item gap-2.5 py-1');
    }

    protected function pillExample(SectionAggregate $aggregate, string $description)
    {
        return _Flex(
            _Html($aggregate->html()),
            _Html($description)->class('roles-legend-desc'),
        )->class('gap-3 py-1');
    }
}
