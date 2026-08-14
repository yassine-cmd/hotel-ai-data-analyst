<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Serves the admin Logs page. Reads the daily system-audit log files and
 * returns the events as parsed, structured entries (the file is plain text in
 * standard Laravel log format; the parser is tolerant of unparseable lines).
 */
class AdminLogsController extends Controller
{
    private const LEVELS = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'];

    public function index(Request $request): JsonResponse
    {
        // useServerTable sends page/per_page plus any filters set via setFilter().
        $page = max((int) $request->query('page', 1), 1);
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $category = $request->query('category');
        $level = $request->query('level');
        $search = trim((string) $request->query('q', ''));
        $clientId = $request->query('client_id');

        // Read every daily file (TTL-bounded) newest-first, so search + pagination
        // span the whole retention window.
        $events = [];
        foreach ($this->logFiles() as $file) {
            foreach ($this->parseFile($file) as $event) {
                $events[] = $event;
            }
        }

        $filtered = array_values(array_filter($events, function (array $ev) use ($category, $level, $search, $clientId) {
            if ($category !== null && $category !== '' && $ev['category'] !== $category) {
                return false;
            }
            if ($level !== null && $level !== '' && $ev['level'] !== strtoupper((string) $level)) {
                return false;
            }
            if ($search !== '' && !str_contains(strtolower((string) ($ev['event'] ?? '') . ' ' . $ev['raw']), strtolower($search))) {
                return false;
            }
            if ($clientId !== null && $clientId !== '' && $ev['client_id'] !== $clientId) {
                return false;
            }

            return true;
        }));

        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $rows = array_slice($filtered, $offset, $perPage);

        $file = $this->currentFile();

        return response()->json([
            'events' => $rows,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
            ],
            'retention_days' => (int) config('python-proxy.audit_retention_days', 30),
            'file' => $file === null ? null : basename($file),
            'size_bytes' => $file !== null && is_file($file) ? filesize($file) : 0,
        ]);
    }

    /** All daily audit files (TTL-bounded), newest first. */
    private function logFiles(): array
    {
        $files = glob(storage_path('logs/system-audit-*.log')) ?: [];
        rsort($files);

        return $files;
    }

    private function currentFile(): ?string
    {
        $path = storage_path('logs/system-audit-' . date('Y-m-d') . '.log');

        return is_file($path) ? $path : null;
    }

    /** Parse a whole log file into structured events, newest first. */
    private function parseFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $events = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $events[] = $this->parseLine($line);
        }

        return array_reverse($events);
    }

    private function parseLine(string $line): array
    {
        if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \S+\.([A-Z]+): (.+)$/', $line, $m)) {
            return $this->base(['raw' => $line, 'category' => 'info']);
        }

        [$ts, $level, $rest] = [$m[1], $m[2], $m[3]];

        $context = [];
        $event = $rest;
        if (preg_match('/\s+(\{.*\})\s*$/', $rest, $cm) && is_array($decoded = json_decode($cm[1], true))) {
            $context = $decoded;
            $event = trim(substr($rest, 0, strpos($rest, $cm[1])));
        }

        $clientId = $context['client_id'] ?? null;

        return $this->base([
            'raw' => $line,
            'time' => $ts,
            'level' => $level,
            'event' => $event,
            'context' => $context,
            'client_id' => $clientId,
            'category' => $this->category($event, $level),
        ]);
    }

    private function category(string $event, string $level): string
    {
        if (str_starts_with($event, 'security.') || str_starts_with($event, 'auth.')) {
            return 'security';
        }

        return match ($level) {
            'CRITICAL', 'ERROR' => 'errors',
            'WARNING' => 'warnings',
            default => 'info',
        };
    }

    private function base(array $overrides): array
    {
        return array_merge([
            'raw' => '',
            'time' => null,
            'level' => null,
            'event' => null,
            'context' => [],
            'client_id' => null,
            'category' => 'info',
        ], $overrides);
    }
}
