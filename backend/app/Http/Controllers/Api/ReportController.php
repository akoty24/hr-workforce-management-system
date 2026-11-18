<?php

namespace App\Http\Controllers\Api;

use App\Reports\ReportBuilder;
use App\Reports\XlsxWriter;
use App\Tenancy\Validation\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * HR reports (admins and HR only): JSON preview or .xlsx download.
 */
class ReportController
{
    public function __construct(private readonly ReportBuilder $reports) {}

    public function __invoke(Request $request, string $type): JsonResponse|BinaryFileResponse
    {
        abort_unless($request->user()->managesHr(), 403);
        abort_unless(in_array($type, ReportBuilder::TYPES, true), 404);

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department_id' => ['nullable', 'uuid', TenantRule::exists('departments')],
            'format' => ['nullable', Rule::in(['json', 'xlsx'])],
        ]);

        $report = $this->reports->build($type, $data['from'], $data['to'], $data['department_id'] ?? null);

        if (($data['format'] ?? 'json') === 'json') {
            return response()->json(['data' => $report]);
        }

        $path = XlsxWriter::write($report['title'], $report['headers'], $report['rows']);
        $name = strtolower($report['title'])."-{$data['from']}-to-{$data['to']}.xlsx";

        return response()
            ->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }
}
