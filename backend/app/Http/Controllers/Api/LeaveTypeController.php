<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use App\Tenancy\Validation\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class LeaveTypeController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', LeaveType::class);

        return LeaveTypeResource::collection(
            LeaveType::query()
                ->unless($request->user()->isAdmin(), fn ($q) => $q->where('is_active', true))
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(Request $request): LeaveTypeResource
    {
        Gate::authorize('create', LeaveType::class);

        return new LeaveTypeResource(LeaveType::create($this->validated($request)));
    }

    public function update(Request $request, LeaveType $leaveType): LeaveTypeResource
    {
        Gate::authorize('update', $leaveType);

        $leaveType->update($this->validated($request, $leaveType));

        return new LeaveTypeResource($leaveType);
    }

    public function destroy(LeaveType $leaveType): Response|JsonResponse
    {
        Gate::authorize('delete', $leaveType);

        if ($leaveType->requests()->exists()) {
            return response()->json([
                'message' => 'This leave type is used by existing requests. Deactivate it instead.',
            ], 409);
        }

        $leaveType->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?LeaveType $type = null): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:80', TenantRule::unique('leave_types', 'name')->ignore($type?->id)],
            'days_per_year' => ['nullable', 'integer', 'min:1', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'A leave type with this name already exists.',
        ]);
    }
}
