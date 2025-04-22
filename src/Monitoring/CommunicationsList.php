<?php

namespace Kompo\Auth\Monitoring;

use Condoedge\Utils\Kompo\Common\Table;
use Kompo\Auth\Models\Monitoring\CommunicationTemplateGroup;

class CommunicationsList extends Table
{
    public $id = 'communications-list';

    public function top()
    {
        return _FlexBetween(
            _Html('communications')->miniTitle(),
            _Button('form')->selfGet('communicationTemplateForm')->inModal(),
        );
    }

    public function query()
    {
        return CommunicationTemplateGroup::latest();
    }

    public function headers()
    {
        return [
            _Th('auth-date'),
            _Th('auth-title'),
            _Th('auth-trigger'),
            _Th('auth-number-of-ways'),
        ];
    }

    public function render($communicationGroup)
    {
        $trigger = $communicationGroup->trigger;

        return _TableRow(
            _Html($communicationGroup->created_at->format('d/m/Y H:i:s')),
            _Html($communicationGroup->title),
            _Html(!$trigger ? '-' : $trigger::getName()),
            _Html($communicationGroup->communicationTemplates()->isValid()->pluck('type')->map(fn($type) => $type->label())->implode(', ')),
        )->selfGet('communicationTemplateForm', ['communicationTemplateGroup' => $communicationGroup->id])->inModal();
    }

    public function communicationTemplateForm($groupId = null)
	{
		return new CommunicationTemplateForm($groupId);
	}
}