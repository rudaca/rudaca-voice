<?php

namespace App\Notifications\Ideas;

use App\Models\Idea;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IdeaCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Idea $idea)
    {
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
        $mail = (new MailMessage)
            ->subject(__('Your idea has been completed -(Idea #:id)', ['id' => $this->idea->id]))
            ->line(__('Good news — your idea ":title" has now been marked as Completed.', ['title' => $this->idea->title]))
            ->line(__('Thank you for helping us improve through your ideas and suggestion. We appreciate it.'));

        $response = $this->idea->officialResponse;

        if ($response !== null) {
            $mail->line(__('Official response:'))
                ->line($response->body);
        }

        return $mail->action(__('View idea'), route('ideas.show', [
            'current_team' => $this->idea->team->slug,
            'idea' => $this->idea->slug,
        ]));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'idea_id' => $this->idea->id,
        ];
    }
}
