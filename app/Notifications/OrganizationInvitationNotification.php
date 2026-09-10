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

    /**
     * An invitation can be accepted, revoked or expired away between the send
     * and the worker picking the job up, and the serialised model is then gone.
     * That is an email nobody wants any more, not a failure to retry, so the
     * job is dropped instead of landing in `failed_jobs`.
     */
    public bool $deleteWhenMissingModels = true;

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
