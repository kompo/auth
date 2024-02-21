<?php

namespace Kompo\Auth\Models\Monitoring;

use Kompo\Auth\Models\Model;

class Notification extends Model
{
    /* RELATIONS */
    public function about()
    {
        return $this->morphTo();
    }

    public function notifier()
    {
        return $this->belongsTo(User::class, 'notifier_id');
    }

    /* SCOPES */
    public function scopeUnread($query)
    {
        $query->whereNull('seen_at');
    }

    /* CALCULATED FIELD */
    public function isUnread()
    {
        return is_null($this->seen_at);
    }

    /* ACTIONS */
    public function markSeen()
    {
        \Cache::forget('discussionsNotifications'.auth()->id());

        $this->seen_at = now();
        $this->save();
    }

    public static function notify($model, $userId, $type = null, $teamId = null)
    {
        $notif = new self();
        $notif->notifier_id = auth()->user() ? auth()->user()->id : null;
        $notif->type = $type;
        $notif->setUserId($userId);
        $notif->setTeamId($teamId);
        $model->notifications()->save($notif);
    }

    /* CALCULATED FIELDS */
    public static function userHasUnseenNotifications($userId, $modelIds, $modelType)
    {
        return static::where('user_id', $userId)
                    ->whereIn('about_id', $modelIds)->where('about_type', $modelType)
                    ->unread()
                    ->count();
    }

    public static function withSpecs($type, $aboutType, $aboutId)
    {
        return static::where('type', $type)
            ->where('about_type', $aboutType)
            ->where('about_id', $aboutId)
            ->withTrashed();
    }

    /* ELEMENTS */
    public function notificationCard($key = 0)
    {
        $content = $this->notificationContent();

        return !$content ? null : _Rows($content)
            ->class('mb-2 p-4 text-sm text-white bg-white bg-opacity-15 pr-12 rounded-2xl border border-white')
            ->style('backdrop-filter: blur(5px);position:relative;z-index:'.max(2, 100 - $key));
    }
}
