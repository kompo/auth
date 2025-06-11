<?php

namespace Kompo\Auth\Models\Teams;

use Kompo\Auth\Mail\ChangeNotificationTeamMail;
use Condoedge\Utils\Models\Model;
use Kompo\Auth\Models\Teams\Team;
use Kompo\Auth\Models\User;

class TeamChange extends Model
{
    /* RELATIONSHIPS */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /* ATTRIBUTES */

    /* CALCULATED FIELDS */

    /* ELEMENTS */


    /* ACTIONS */
    public static function addWithMessage($message)
    {
        $change = new static();
        $change->team_id = currentTeamId();
        $change->user_id = auth()->id();
        $change->message = $message;
        $change->save();

        $email = currentTeam()->getNotificationsEmailAddress();

        if (!$email) {
            return;
        }

        \Mail::to($email)->send(new ChangeNotificationTeamMail($change));
    }
}
