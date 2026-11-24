<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AccountEmailChanged extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Email tài khoản VietFeed đã thay đổi')
            ->line('Email đăng nhập của tài khoản VietFeed vừa được đổi sang địa chỉ khác.')
            ->line('Nếu bạn không yêu cầu thay đổi này, hãy liên hệ bộ phận hỗ trợ VietFeed.');
    }
}
