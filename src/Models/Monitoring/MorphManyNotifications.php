<?php 

namespace Kompo\Auth\Models\Monitoring;

use Kompo\Auth\Facades\NotificationModel;

trait MorphManyNotifications
{
    public function notifications()
    {
        return $this->morphMany(NotificationModel::getFacadeRoot()::class, 'about');
    }

    public function notification()
    {
        return $this->morphOne(NotificationModel::getFacadeRoot()::class, 'about')->forAuthUser();
    }

    public function unreadNotifications()
    {
        return $this->notifications()->unread()->forAuthUser();
    }

    /* ACTIONS */
    public function deleteNotifications()
    {
    	$this->notifications->each->delete();
    }
}