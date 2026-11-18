<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\Salary;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Salary history. Admins and HR see and edit everyone's; an employee sees
 * only their own. Team leaders do not see their team's salaries.
 */
class SalaryController
{
    public function index(Request $request, Employee $employee): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->managesHr() || $employee->user_id === $user->id, 403);

        $history = Salary::query()
            ->with('creator')
            ->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->get();

        $current = Salary::currentFor([$employee->id])[$employee->id] ?? null;

        return response()->json(['data' => [
            'current' => $current ? $this->present($current) : null,
            'history' => $history->map($this->present(...))->values(),
        ]]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($request->user()->managesHr(), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $salary = Salary::create([
            'employee_id' => $employee->id,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency'] ?? 'EGP'),
            'effective_from' => $data['effective_from'],
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->present($salary->load('creator'))], 201);
    }

    public function destroy(Request $request, Salary $salary): Response
    {
        abort_unless($request->user()->managesHr(), 403);

        $salary->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Salary $salary): array
    {
        return [
            'id' => $salary->id,
            'amount' => (float) $salary->amount,
            'currency' => $salary->currency,
            'effective_from' => $salary->effective_from->toDateString(),
            'note' => $salary->note,
            'created_by' => $salary->relationLoaded('creator') ? $salary->creator?->name : null,
        ];
    }
}
