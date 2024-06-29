<?php

namespace Kompo\Auth\Http\Controllers;

use App\Models\Notification;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Kompo\Auth\Facades\NotificationModel;

class NotificationsController extends Controller
{
    public function remind($id, $reminderDays)
    {
    	$notification = NotificationModel::findOrFail($id);

    	if ($notification->user_id !== auth()->user()->id) {
    		abort(403);
    	}

    	$notification->status = 1;
    	$notification->reminder_at = Carbon::now()->addDays($reminderDays);
    	$notification->save();
    }

    public function delete($id)
    {
    	$notification = Notification::findOrFail($id);

    	if ($notification->user_id !== auth()->user()->id) {
    		abort(403);
    	}

        //Delete other notifications of same type and parent
        NotificationModel::where('type', $notification->type)
            ->where('about_id', $notification->about_id)->where('about_type', $notification->about_type)
            ->get()->each->delete();

        //Delete Notification
    	$notification->delete();
    }
}
