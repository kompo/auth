<?php

namespace Kompo\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Kompo\Auth\Models\AuthorizationCode;

class AuthorizationCodeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    protected AuthorizationCode $authorizationCode;

    /**
     * Create a new message instance.
     */
    public function __construct(AuthorizationCode $authorizationCode)
    {
        $this->authorizationCode = $authorizationCode;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.authorization-code-mail'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.authorization-code',
            with: [
                'authorizationCode' => $this->authorizationCode,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
