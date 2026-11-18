<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Public holiday (one or more consecutive days) of the current company.
 *
 * @property string $id
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 */
class Holiday extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['name', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * Holiday dates (Y-m-d) between two dates, inclusive.
     *
     * @return array<string, true>
     */
    public static function datesBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];

        $holidays = self::query()
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->get();

        foreach ($holidays as $holiday) {
            foreach (CarbonPeriod::create($holiday->start_date, $holiday->end_date) as $day) {
                $dates[$day->toDateString()] = true;
            }
        }

        return $dates;
    }
}
