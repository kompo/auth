<?php

namespace Kompo\Auth\Teams\Mail;

use Kompo\Auth\Models\Teams\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invitation;

    public function __construct(TeamInvitation $invitation)
    {
        $this->invitation = $invitation;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject(__('teams-team-invitation'))
            ->markdown('kompo-auth::mail.team-invitation', [
                'acceptUrl' => $this->invitation->getAcceptInvitationRoute(),
            ]);
    }
}
