<?php

namespace Kompo\Auth\Monitoring;

use Kompo\Auth\Models\Monitoring\Activity;
use Kompo\Query;

class ActivityList extends Query
{
    public $class = 'vlQueryHorizontal';

    public $paginationType = 'Scroll';
    public $itemsWrapperClass = 'overflow-y-auto mini-scroll';
    public $itemsWrapperStyle = 'max-height: 450px';

    protected $teamId;

    public function created()
    {
        $this->teamId = currentTeamId();
    }

    public function query()
    {
        return Activity::where('team_id', $this->teamId);
    }

    public function top()
    {
        return _TitleCard('ka::dashboard.team-daily-activity');
    }

    public function render($activity)
    {
        if(!$activity->concern) {
            return;
        }

        return $activity->gotoRoute()[$activity->parent_type](
            _Flex(
                _Html()->icon(_Sax($activity->icon))->class('text-2xl text-gray-600 mr-2'),
                _Rows(
                    _Html($activity->title)->class('text-sm font-medium'),
                    _UserDate($activity->user->name, $activity->created_at)
                )
            )->class('p-2 cursor-pointer')
            ->alignStart()
        );
    }

}
