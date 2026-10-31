<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountEmailChanged extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct()
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('KPS — Email đăng nhập đã thay đổi')
            ->line('Email đăng nhập KPS của bạn vừa được thay đổi sau khi xác minh địa chỉ mới.')
            ->line('Nếu bạn không thực hiện thay đổi này, hãy liên hệ quản trị viên KPS ngay để bảo vệ tài khoản.');
    }
}
