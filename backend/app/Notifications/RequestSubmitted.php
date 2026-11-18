<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Models\ShortLeave;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to whoever decides a new leave / permission request (the team
 * leader, or admins and HR when the employee has no team leader).
 * Queued after the transaction commits; the tenant travels with the job.
 */
class RequestSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LeaveRequest|ShortLeave $request)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $describe = RequestText::describe($this->request);

        return (new MailMessage)
            ->subject("New {$describe['kind']} request from {$describe['employee']}")
            ->greeting('Hello'.(isset($notifiable->name) ? " {$notifiable->name}" : '').',')
            ->line("{$describe['employee']} submitted a {$describe['kind']} request that is waiting for your decision.")
            ->line($describe['when'])
            ->when($describe['reason'], fn (MailMessage $m) => $m->line('Reason: '.$describe['reason']))
            ->action('Review the request', RequestText::url($this->request));
    }
}
