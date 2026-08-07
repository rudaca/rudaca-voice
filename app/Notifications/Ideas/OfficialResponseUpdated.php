<?php

namespace App\Notifications\Ideas;

use App\Models\IdeaOfficialResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OfficialResponseUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public IdeaOfficialResponse $response)
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
        $idea = $this->response->idea;

        return (new MailMessage)
            ->subject(__('The official response on ":title" was updated', ['title' => $idea->title]))
            ->line(__('The official response on an idea you submitted or voted for has been updated.'))
            ->line($this->response->body)
            ->action(__('View idea'), route('ideas.show', [
                'current_team' => $idea->team->slug,
                'idea' => $idea->slug,
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
            'idea_id' => $this->response->idea_id,
            'official_response_id' => $this->response->id,
        ];
    }
}
