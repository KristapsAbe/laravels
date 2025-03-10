<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FriendRequestNotification extends Notification
{
    use Queueable;

    protected $friendRequest;

    public function __construct($friendRequest)
    {
        $this->friendRequest = $friendRequest;
    }

    public function via($notifiable)
    {
        return ['database']; // If you're storing notifications in the database
    }

    public function toArray($notifiable)
    {
        return [
            'message' => "{$this->friendRequest['sender_name']} sent you a friend request",
            'friend_request_id' => $this->friendRequest['id'],
        ];
    }
}
