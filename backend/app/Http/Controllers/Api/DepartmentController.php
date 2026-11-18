<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Reference tenant-owned CRUD module. Isolation comes from the platform
 * (tenant middleware, tenant scope on route binding, TenantRule validation);
 * this controller contains business logic only.
 */
class DepartmentController
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Department::class);

        return DepartmentResource::collection(
            Department::query()->withCount('employees')->orderBy('name')->paginate(100),
        );
    }

    public function store(DepartmentRequest $request): DepartmentResource
    {
        Gate::authorize('create', Department::class);

        return new DepartmentResource(Department::create($request->validated()));
    }

    public function show(Department $department): DepartmentResource
    {
        Gate::authorize('view', $department);

        return new DepartmentResource($department);
    }

    public function update(DepartmentRequest $request, Department $department): DepartmentResource
    {
        Gate::authorize('update', $department);

        $department->update($request->validated());

        return new DepartmentResource($department);
    }

    public function destroy(Department $department): Response|JsonResponse
    {
        Gate::authorize('delete', $department);

        if ($department->employees()->exists()) {
            return response()->json([
                'message' => 'This department still has employees. Move them to another department first.',
            ], 409);
        }

        $department->delete();

        return response()->noContent();
    }
}
