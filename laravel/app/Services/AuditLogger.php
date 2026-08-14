<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Writes system audit events to the dedicated `audit` log channel (one
 * plain-text line per event, standard Laravel format) and, on client
 * instances, forwards them to Python for relay to the admin instance's log.
 *
 * Event names are dotted identifiers with a category prefix, e.g.
 *   auth.login.failed, security.loopback_violation, error.proxy.unreachable,
 *   api.analyze.request. The admin UI groups them by category (security,
 *   errors, warnings, info) and renders the context as expandable detail.
 */
class AuditLogger
{
    public function __construct(
        private ClientSignatureService $signature,
    ) {}

    public function info(string $event, array $context = []): void
    {
        $this->log('info', $event, $context);
    }

    public function warning(string $event, array $context = []): void
    {
        $this->log('warning', $event, $context);
    }

    public function error(string $event, array $context = []): void
    {
        $this->log('error', $event, $context);
    }

    public function critical(string $event, array $context = []): void
    {
        $this->log('critical', $event, $context);
    }

    public function log(string $level, string $event, array $context = []): void
    {
        $this->writeLocal($level, $event, $context);
        $this->forward($level, $event, $context);
    }

    /**
     * Write to the local audit file only — never forward. Used by the admin
     * instance's internal events receiver so relayed events can never be
     * echoed back to Python.
     */
    public function writeLocal(string $level, string $event, array $context = []): void
    {
        Log::channel('audit')->{$level}($event, $context);
    }

    /**
     * Forward the event to Python (/api/events), which relays it to the admin
     * instance. Fire-and-forget: failures are logged and swallowed so the
     * request path is never broken by a broken forward target.
     */
    private function forward(string $level, string $event, array $context): void
    {
        $url = config('python-proxy.audit_forward_url', '');
        $privateKey = config('python-proxy.client_private_key', '');
        if ($url === '' || $privateKey === '') {
            return;
        }

        try {
            $body = json_encode([
                'client_id' => $context['client_id'] ?? null,
                'level' => $level,
                'event' => $event,
                'context' => $context,
            ]);

            ['timestamp' => $timestamp, 'signature' => $signature] = $this->signature->sign((string) $body, $privateKey);

            Http::withHeaders([
                'X-Timestamp' => (string) $timestamp,
                'X-Signature' => $signature,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->connectTimeout(1)
                ->timeout(2)
                ->post($url, json_decode((string) $body, true));
        } catch (\Throwable $e) {
            Log::channel('audit')->warning('audit.forward_failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
