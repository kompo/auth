<?php

namespace Kompo\Auth\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Kompo\Auth\Facades\NotificationModel;

class NotificationsController extends Controller
{
	public function goToButtonAction($notification_id)
	{
		$notification = NotificationModel::findOrFail($notification_id);
		if($notification->user_id != auth()->user()->id) {
			abort(403);
		}
		$notification->markSeen();

		// We started showing notification that are not in the current team
		// So now we need to ensure that the user is in the team of the notification before redirecting
		if ($notification->team_id && currentTeamId() != $notification->team_id) {
			if (auth()->user()->canAccessTeam($notification->team_id)) {
				auth()->user()->switchToFirstTeamRole($notification->team_id);
			} else {
				abort(403, __('You do not have access to the team related to this notification.'));
			}
		}

		return response()->kompoRedirect($notification->custom_button_href ?? '/');
	}
	
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
    	$notification = NotificationModel::findOrFail($id);

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
