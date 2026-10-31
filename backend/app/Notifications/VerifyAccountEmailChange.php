<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyAccountEmailChange extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $token)
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
        $url = rtrim((string) config('account.frontend_url'), '/').'/account#email-change='.$this->token;

        return (new MailMessage)
            ->subject('KPS — Xác nhận thay đổi email')
            ->line('Có yêu cầu sử dụng địa chỉ này làm email đăng nhập KPS. Email hiện tại chưa thay đổi.')
            ->action('Xác nhận email mới', $url)
            ->line('Đăng nhập tài khoản đã gửi yêu cầu và nhập lại mật khẩu để xác nhận. Liên kết hết hạn sau '.config('account.email_change_minutes').' phút; chỉ liên kết mới nhất có hiệu lực.')
            ->line('Nếu bạn không gửi yêu cầu, hãy bỏ qua email này.');
    }
}
