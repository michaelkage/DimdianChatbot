<?php
declare(strict_types=1);

/** Structured, privacy-minimized operational logging. Never log message bodies,
 * phone numbers, tokens, session tokens or raw provider responses. */
function app_request_id(): string
{
    static $requestId = null;
    if ($requestId === null) {
        $requestId = bin2hex(random_bytes(16));
        if (PHP_SAPI !== 'cli' && !headers_sent()) header('X-DIMDIAN-REQUEST-ID: ' . $requestId);
    }
    return $requestId;
}

function app_sanitize_log_context(array $context): array
{
    $allowed = ['endpoint','status','reason_code','job_id','attempt','provider_code','exception_class','duration_ms','operation','queue','count','result'];
    $clean = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $context)) continue;
        $value = $context[$key];
        if (is_int($value) || is_float($value) || is_bool($value)) $clean[$key] = $value;
        elseif (is_string($value)) $clean[$key] = substr(preg_replace('/[^a-zA-Z0-9_.:-]/', '', $value) ?? '', 0, 80);
    }
    return $clean;
}

function app_log_event(string $event, array $context = []): void
{
    $event = preg_replace('/[^a-zA-Z0-9_.-]/', '', $event) ?: 'app.event';
    $record = [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'request_id' => app_request_id(),
        'event' => substr($event, 0, 100),
        'context' => app_sanitize_log_context($context),
    ];
    error_log('[DimdianChatbot] ' . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}
