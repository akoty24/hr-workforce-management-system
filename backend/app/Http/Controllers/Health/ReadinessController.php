<?php

namespace App\Http\Controllers\Health;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Readiness probe: is this instance able to serve real traffic?
 *
 * Unlike the liveness probe (/up), this verifies dependencies — currently a
 * real round-trip to the database. Returns 200 when ready, 503 otherwise.
 * Failure details are logged, never returned, because the endpoint is public.
 */
class ReadinessController
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
        ];

        $ready = collect($checks)->every(fn (array $check) => $check['status'] === 'ok');

        return response()
            ->json([
                'status' => $ready ? 'ok' : 'unavailable',
                'checks' => $checks,
            ], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @return array{status: string, latency_ms?: float}
     */
    private function checkDatabase(): array
    {
        $started = hrtime(true);

        try {
            $connection = $this->db->connection();
            $connection->select('SELECT 1');

            return [
                'status' => 'ok',
                'latency_ms' => round((hrtime(true) - $started) / 1e6, 2),
            ];
        } catch (Throwable $e) {
            Log::error('Readiness check failed: database unreachable', [
                'connection' => $this->db->getDefaultConnection(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            // Drop the broken connection so the next probe reconnects cleanly.
            $this->db->purge();

            return ['status' => 'fail'];
        }
    }
}
