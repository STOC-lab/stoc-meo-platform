<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email a prospective member receives when they are invited to an
 * organization.
 */
class OrganizationInvitationMail extends Mailable
{
    public function __construct(
        public Invitation $invitation,
        public string $acceptUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->invitation->organization->name} への招待",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invitation',
            with: [
                'organizationName' => $this->invitation->organization->name,
                'inviterName' => $this->invitation->inviter?->name,
                'roleLabel' => $this->invitation->role->label(),
                'acceptUrl' => $this->acceptUrl,
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
