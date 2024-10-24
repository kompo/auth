<?php

namespace Kompo\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ChangeNotificationTeamMail extends Mailable
{
    use Queueable, SerializesModels;

    protected $teamChange;
    protected $team;
    protected $user;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($teamChange)
    {
        $this->teamChange = $teamChange;
        $this->team = $teamChange->team;
        $this->user = $this->team->owner;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->markdown('kompo-auth::mail.team-change')
            ->subject(__('translate.team-change'))
            ->with([
                'teamName' => $this->team->name,
                'userName' => $this->user->name,
                'changes' => $this->teamChange->message,
            ]);
    }
}
