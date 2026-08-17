<?php

namespace App\Notifications\Auth;

use App\Models\OwnerRecoveryToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OwnerRecoveryRequested extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * `$code` is the plaintext one-time code — it exists only for the
     * duration of this notification and is never persisted anywhere
     * (the token model only stores its hash).
     */
    public function __construct(
        public OwnerRecoveryToken $token,
        public string $code,
    ) {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $team = $this->token->team;

        return (new MailMessage)
            ->subject(__('Recover owner access to :teamName', ['teamName' => $team->name]))
            ->line(__('A request was made to recover owner access to :teamName.', ['teamName' => $team->name]))
            ->line(__('If you did not make this request, you can safely ignore this email.'))
            ->action(__('Continue recovery'), route('org.recovery.show', ['team' => $team, 'token' => $this->token]))
            ->line(__('Enter this one-time code on that page to finish: :code', ['code' => $this->code]))
            ->line(__('This link and code expire in 15 minutes.'));
    }
}
