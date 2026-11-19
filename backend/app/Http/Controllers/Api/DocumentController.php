<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Tenancy\TenantFileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee documents. Admins and HR upload, download and delete; employees
 * can see and download their own (read-only).
 */
class DocumentController
{
    public function __construct(private readonly TenantFileStorage $files) {}

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeView($request->user(), $employee);

        $documents = EmployeeDocument::query()
            ->where('employee_id', $employee->id)
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $documents->map(fn (EmployeeDocument $d) => $this->document($d))]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($request->user()->managesHr(), 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
            'type' => ['required', Rule::in(EmployeeDocument::TYPES)],
            'title' => ['required', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
        ], [
            'file.max' => 'The file may not be larger than 10 MB.',
            'file.mimes' => 'Upload a PDF, image, Word or Excel file.',
        ]);

        $file = $data['file'];
        $path = 'documents/'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension());

        $document = DB::transaction(function () use ($request, $employee, $data, $file, $path) {
            $this->files->put($path, $file->get());

            return EmployeeDocument::create([
                'employee_id' => $employee->id,
                'type' => $data['type'],
                'title' => trim($data['title']),
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
                'expires_at' => $data['expires_at'] ?? null,
                'uploaded_by' => $request->user()->id,
            ]);
        });

        return response()->json(['data' => $this->document($document)], 201);
    }

    public function download(Request $request, EmployeeDocument $document): StreamedResponse
    {
        $this->authorizeView($request->user(), $document->employee);
        abort_unless($this->files->exists($document->path), 404, 'The file is missing from storage.');

        $contents = (string) $this->files->get($document->path);

        return response()->streamDownload(fn () => print ($contents), $document->original_name, [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, EmployeeDocument $document): Response
    {
        abort_unless($request->user()->managesHr(), 403);

        $this->files->delete($document->path);
        $document->delete();

        return response()->noContent();
    }

    /** Admins/HR: documents already expired or expiring within 30 days. */
    public function expiring(Request $request): JsonResponse
    {
        abort_unless($request->user()->managesHr(), 403);

        $documents = EmployeeDocument::query()
            ->with('employee')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays(EmployeeDocument::WARN_DAYS)->toDateString())
            ->orderBy('expires_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $documents->map(fn (EmployeeDocument $d) => $this->document($d) + [
            'employee' => ['id' => $d->employee->id, 'full_name' => $d->employee->full_name],
        ])]);
    }

    private function authorizeView(User $user, Employee $employee): void
    {
        abort_unless($user->managesHr() || $employee->user_id === $user->id, 403);
    }

    /** @return array<string, mixed> */
    private function document(EmployeeDocument $d): array
    {
        $daysLeft = $d->expires_at ? (int) now()->startOfDay()->diffInDays($d->expires_at->startOfDay(), false) : null;

        return [
            'id' => $d->id,
            'type' => $d->type,
            'title' => $d->title,
            'original_name' => $d->original_name,
            'mime' => $d->mime,
            'size' => $d->size,
            'expires_at' => $d->expires_at?->toDateString(),
            'days_left' => $daysLeft,
            'status' => $daysLeft === null ? 'valid' : ($daysLeft < 0 ? 'expired' : ($daysLeft <= EmployeeDocument::WARN_DAYS ? 'expiring' : 'valid')),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
