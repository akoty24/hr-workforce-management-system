<?php

namespace App\Http\Controllers\Api;

use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Public holidays. Changes apply to leave requests submitted afterwards;
 * the day count of existing requests is not recalculated.
 */
class HolidayController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Holiday::class);

        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? now()->year);

        $holidays = Holiday::query()
            ->where(fn ($q) => $q->whereYear('start_date', $year)->orWhereYear('end_date', $year))
            ->orderBy('start_date')
            ->get();

        return response()->json(['data' => $holidays->map($this->present(...))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Holiday::class);

        $holiday = Holiday::create($this->validated($request));

        return response()->json(['data' => $this->present($holiday)], 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        Gate::authorize('update', $holiday);

        $holiday->update($this->validated($request));

        return response()->json(['data' => $this->present($holiday)]);
    }

    public function destroy(Holiday $holiday): Response
    {
        Gate::authorize('delete', $holiday);

        $holiday->delete();

        return response()->noContent();
    }

    /**
     * @return array{name: string, start_date: string, end_date: string}
     */
    private function validated(Request $request): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        if (! $request->filled('end_date')) {
            $request->merge(['end_date' => $request->input('start_date')]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', function ($attribute, $value, $fail) use ($request) {
                if (strtotime($value) - strtotime((string) $request->input('start_date')) > 30 * 86400) {
                    $fail('A holiday cannot be longer than 31 days.');
                }
            }],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'name' => $holiday->name,
            'start_date' => $holiday->start_date->toDateString(),
            'end_date' => $holiday->end_date->toDateString(),
            'days' => (int) $holiday->start_date->diffInDays($holiday->end_date) + 1,
        ];
    }
}
