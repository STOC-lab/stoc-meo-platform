<?php

namespace App\Notifications;

use App\Mail\OrganizationInvitationMail;
use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Delivers the invitation email. The invitee usually has no account yet, so
 * this is sent as an on-demand notification to a bare address.
 */
class OrganizationInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invitation $invitation,
        public string $acceptUrl,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): OrganizationInvitationMail
    {
        return (new OrganizationInvitationMail($this->invitation, $this->acceptUrl))
            ->to($this->invitation->email);
    }
}
