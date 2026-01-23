<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionRequiredNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private Company $company
    ) {}

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
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
        $subscribeUrl = $frontendUrl . '/subscribe';

        return (new MailMessage)
            ->subject('Company Approved - Subscription Required')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your company **' . $this->company->name . '** has been approved by our team.')
            ->line('To activate your account and start using LEO24 CRM, you need to complete your subscription.')
            ->action('Subscribe Now', $subscribeUrl)
            ->line('Once you complete your subscription, your account will be activated and you\'ll have full access to the platform.')
            ->line('If you have any questions, please contact our support team.')
            ->salutation('Best regards, LEO24 CRM Team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'company_id' => $this->company->id,
            'company_name' => $this->company->name,
        ];
    }
}

