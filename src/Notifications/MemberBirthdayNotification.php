<?php

/**
 * © 2026 VISUAL WEBER COMPANY LIMITED. All rights reserved.
 * Proprietary software developed and distributed by Visual Weber.
 * Use is permitted only under a valid license agreement.
 *
 * © 2026 CÔNG TY TNHH VISUAL WEBER. Bảo lưu mọi quyền.
 * Phần mềm độc quyền của Visual Weber, chỉ được sử dụng theo Hợp đồng cấp phép.
 */

namespace Dev\Kernel\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\URL;
use Illuminate\Queue\InteractsWithQueue;

use Dev\Base\Facades\EmailHandler;

class MemberBirthdayNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */

    public function __construct(public $event)
    {
        $this->event = $event;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed $notifiable
     * @return array
     */
    public function via($notifiable): array
    {
        // Không có email thì không có gì để gửi; trước đây toMail() vẫn chạy và lỗi biến chưa định nghĩa.
        return blank($notifiable->email ?? null) ? [] : ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable): MailMessage
    {
        try {
            if (!blank($notifiable->email)) {
                $args = [
                    // 'attachments' => [
                    //     public_path("files/SOL24.0830 [in] NovaWorld Phan Thiet - Server Architecture and Website Deployment V4.pdf"),
                    //     public_path("files/SOL24.0830 [in] NovaWorld Phan Thiet - Server Architecture and Website Deployment V4.pdf")
                    // ]
                ];

                $emailHandler = EmailHandler::setModule(KERNEL_MODULE_SCREEN_NAME);
                if ($emailHandler->templateEnabled('member-birthday-reminder-notification', 'kernel')) {
                    $emailHandler
                        ->setType("kernel")
                        ->setPlatformPath("dev-extensions")
                        ->setVariableValues([
                            'email',
                            $notifiable->email
                        ])
                        ->sendUsingTemplate(
                            'member-birthday-reminder-notification',
                            'toan@visualweber.com' ?: null,
                            $args,
                            true, // debug
                            "kernel"
                        );
                }
            }

            return (new MailMessage())
                ->view(['html' => new HtmlString($emailHandler->getContent())])
                ->subject($emailHandler->getSubject());
        } catch (\Throwable $th) {
            // dd() dừng hẳn tiến trình worker; ghi log rồi ném lại để queue đánh dấu job lỗi và thử lại.
            apps_log('birthday-notification')->error(__METHOD__ . ': ' . $th->getMessage());
            throw $th;
        }
    }
}
