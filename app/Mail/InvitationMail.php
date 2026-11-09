<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Access\Models\Invitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends Mailable
{
    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invitation',
            with: [
                'url' => $this->invitation->acceptUrl(),
                'inviter' => $this->invitation->inviter?->name,
                'teamName' => $this->invitation->team?->name,
                'role' => $this->invitation->role?->label(),
                'administrator' => $this->invitation->is_admin,
                'days' => Invitation::VALID_DAYS,
            ],
        );
    }
}
