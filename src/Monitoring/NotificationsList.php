<?php

namespace Kompo\Auth\Monitoring;

use Illuminate\Support\Carbon;
use Kompo\Auth\Facades\NotificationModel;
use Kompo\Query;

class NotificationsList extends Query
{
    public $paginationType = 'Scroll';
    public $itemsWrapperClass = 'overflow-y-auto mini-scroll relative z-1 pt-4 pb-20';
    public $itemsWrapperStyle = 'max-height: 450px';

    public function noItemsFound()
    {
        return _Html('dashboard-no-notifications')->icon('icon-check')
            ->class('text-white text-sm');
    }

    public function query()
    {
        return NotificationModel::whereNotNull('type')->with('about')
            // ->where('team_id', currentTeam()->id) // Now showing all of them without restricting to current team!
            ->where('user_id', auth()->user()->id)
            ->where(function($q){
                $q->whereNull('status')
                    ->orWhere(function($q){
                        $q->whereNotNull('reminder_at')
                          ->where('reminder_at', '<=', Carbon::now());
                    });
            })
            ->whereNull('seen_at')
            ->latest();
    }

    public function render($notification, $key)
    {
        return $notification->notificationCard($key); //key => decreasing z-index => dropdown button don't get blurred
    }
}
