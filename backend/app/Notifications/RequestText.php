<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Models\ShortLeave;

/**
 * Shared wording for request e-mails.
 */
final class RequestText
{
    /**
     * @return array{kind: string, employee: string, when: string, reason: string|null}
     */
    public static function describe(LeaveRequest|ShortLeave $request): array
    {
        if ($request instanceof ShortLeave) {
            return [
                'kind' => 'permission',
                'employee' => $request->employee->full_name,
                'when' => sprintf('Date: %s, from %s to %s (%d minutes).',
                    $request->date->toDateString(), substr($request->start_time, 0, 5), substr($request->end_time, 0, 5), $request->minutes),
                'reason' => $request->reason,
            ];
        }

        return [
            'kind' => 'leave',
            'employee' => $request->employee->full_name,
            'when' => sprintf('%s: %s to %s (%d working day%s).',
                $request->leaveType->name, $request->start_date->toDateString(), $request->end_date->toDateString(),
                $request->days, $request->days === 1 ? '' : 's'),
            'reason' => $request->reason,
        ];
    }

    public static function url(LeaveRequest|ShortLeave $request): string
    {
        return rtrim((string) config('app.url'), '/').($request instanceof ShortLeave ? '/permissions' : '/leave');
    }
}
