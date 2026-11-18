<?php

return [

    /*
    | Weekend days excluded when counting leave days, as ISO-8601 weekday
    | numbers (1 = Monday ... 7 = Sunday). Default: Friday and Saturday.
    | Example for a Saturday/Sunday weekend: HR_WEEKEND_DAYS=6,7
    */
    'weekend_days' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('HR_WEEKEND_DAYS', '5,6')),
    ), fn (int $day) => $day >= 1 && $day <= 7)),

    /*
    | Longest hourly permission ("short leave") an employee may request in a
    | single day, in minutes.
    */
    'short_leave_max_minutes' => (int) env('HR_SHORT_LEAVE_MAX_MINUTES', 120),

];
