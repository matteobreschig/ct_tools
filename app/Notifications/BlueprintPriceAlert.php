<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;

class BlueprintPriceAlert extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $body,
    ) {}

    public function via(object $notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $title = htmlspecialchars($this->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = htmlspecialchars($this->body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return TelegramMessage::create()
            ->to($notifiable->telegram_chat_id)
            ->options(['parse_mode' => 'HTML'])
            ->content("<b>{$title}</b>\n{$body}");
    }
}