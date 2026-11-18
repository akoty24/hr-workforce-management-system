<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Models\ShortLeave;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the employee when their leave / permission request is approved
 * or rejected.
 */
class RequestDecided extends Notification implements ShouldQueue
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
        $status = $this->request->status->value; // approved | rejected
        $by = $this->request->decider?->name;

        return (new MailMessage)
            ->subject("Your {$describe['kind']} request was {$status}")
            ->greeting("Hello {$describe['employee']},")
            ->line("Your {$describe['kind']} request was {$status}".($by ? " by {$by}" : '').'.')
            ->line($describe['when'])
            ->when($this->request->decision_note, fn (MailMessage $m) => $m->line('Note: '.$this->request->decision_note))
            ->action('Open', RequestText::url($this->request));
    }
}
