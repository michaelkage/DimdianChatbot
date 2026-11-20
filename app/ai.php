<?php
declare(strict_types=1);

interface ChatBridgeAIProvider
{
    /** @return array{content:string,input_tokens:int,output_tokens:int,model:string} */
    public function generate(array $messages, int $maxOutputTokens): array;
}

/**
 * Provider-neutral boundary with an OpenAI Chat Completions-compatible adapter.
 * Other providers can implement ChatBridgeAIProvider without changing the queue.
 */
final class OpenAICompatibleChatProvider implements ChatBridgeAIProvider
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct(string $baseUrl, string $apiKey, string $model, int $timeout = 25)
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('AI provider base URL must use HTTPS.');
        }
        if ($apiKey === '' || $model === '') throw new InvalidArgumentException('AI provider key and model are required.');
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = max(5, min(60, $timeout));
    }

    public function generate(array $messages, int $maxOutputTokens): array
    {
        $url = $this->baseUrl . '/chat/completions';
        $payload = json_encode([
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => max(64, min(2000, $maxOutputTokens)),
            'temperature' => 0.2,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) throw new RuntimeException('Could not encode AI request.');

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer " . $this->apiKey . "\r\n",
                'content' => $payload,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
        }
        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new RuntimeException('AI provider request failed with HTTP ' . ($status ?: 'network error') . '.');
        }
        $decoded = json_decode($response, true);
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('AI provider returned no assistant text.');
        }
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        return [
            'content' => trim($content),
            'input_tokens' => max(0, (int)($usage['prompt_tokens'] ?? 0)),
            'output_tokens' => max(0, (int)($usage['completion_tokens'] ?? 0)),
            'model' => (string)($decoded['model'] ?? $this->model),
        ];
    }
}

function chatbridge_ai_queue_or_handover(PDO $pdo, string $tenantId, string $conversationId, string $messageId, string $messageText): void
{
    if (preg_match('/\b(human|person|staff|agent|representative|real person|speak to someone)\b/i', $messageText)) {
        chatbridge_create_handover($pdo, $tenantId, $conversationId, 'visitor_requested');
        return;
    }

    $settings = $pdo->prepare('SELECT enabled FROM tenant_ai_settings WHERE tenant_id = :tenant_id LIMIT 1');
    $settings->execute([':tenant_id' => $tenantId]);
    if ((int)$settings->fetchColumn() !== 1) return;

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO ai_jobs
            (id, tenant_id, conversation_id, source_message_id, status, attempts, available_at, created_at, updated_at)
         VALUES (:id, :tenant_id, :conversation_id, :message_id, "pending", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $insert->execute([
        ':id' => uuid_v4(),
        ':tenant_id' => $tenantId,
        ':conversation_id' => $conversationId,
        ':message_id' => $messageId,
    ]);
}

function chatbridge_create_handover(PDO $pdo, string $tenantId, string $conversationId, string $reason): void
{
    $allowed = ['visitor_requested', 'low_knowledge', 'ai_error', 'sensitive_topic', 'staff_requested', 'budget_exhausted'];
    if (!in_array($reason, $allowed, true)) $reason = 'ai_error';
    $existing = $pdo->prepare(
        'SELECT id FROM conversation_handoffs
         WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id
           AND status IN ("open", "assigned") LIMIT 1'
    );
    $existing->execute([':tenant_id' => $tenantId, ':conversation_id' => $conversationId]);
    if ($existing->fetchColumn()) return;
    $insert = $pdo->prepare(
        'INSERT INTO conversation_handoffs
            (id, tenant_id, conversation_id, reason, status, created_at)
         VALUES (:id, :tenant_id, :conversation_id, :reason, "open", UTC_TIMESTAMP())'
    );
    $insert->execute([
        ':id' => uuid_v4(),
        ':tenant_id' => $tenantId,
        ':conversation_id' => $conversationId,
        ':reason' => $reason,
    ]);
}

function chatbridge_retrieve_knowledge(PDO $pdo, string $tenantId, string $question, int $limit = 5): array
{
    $limit = max(1, min(8, $limit));
    $query = $pdo->prepare(
        'SELECT id, title, content,
                MATCH(title, content) AGAINST (:search_score IN NATURAL LANGUAGE MODE) AS relevance
         FROM knowledge_sources
         WHERE tenant_id = :tenant_id AND status = "published"
           AND MATCH(title, content) AGAINST (:search_filter IN NATURAL LANGUAGE MODE)
         ORDER BY relevance DESC
         LIMIT ' . $limit
    );
    $query->execute([
        ':search_score' => $question,
        ':tenant_id' => $tenantId,
        ':search_filter' => $question,
    ]);
    return $query->fetchAll();
}

function process_tenant_ai_jobs(PDO $pdo, string $tenantId, int $batchSize = 5, int $maxAttempts = 3): array
{
    $summary = ['generated' => 0, 'handed_over' => 0, 'failed' => 0, 'skipped' => 0];
    $settingsQuery = $pdo->prepare('SELECT * FROM tenant_ai_settings WHERE tenant_id = :tenant_id AND enabled = 1 LIMIT 1');
    $settingsQuery->execute([':tenant_id' => $tenantId]);
    $settings = $settingsQuery->fetch();
    if (!$settings) return $summary;

    $usageQuery = $pdo->prepare(
        'SELECT COALESCE(SUM(total_tokens), 0)
         FROM ai_usage_events
         WHERE tenant_id = :tenant_id AND created_at >= DATE_FORMAT(UTC_TIMESTAMP(), "%Y-%m-01 00:00:00")'
    );
    $usageQuery->execute([':tenant_id' => $tenantId]);
    $usedTokens = (int)$usageQuery->fetchColumn();
    $budget = (int)$settings['monthly_token_budget'];
    if ($budget > 0 && $usedTokens >= $budget) return $summary;

    $apiKey = chatbridge_decrypt_secret((string)$settings['api_key_ciphertext']);
    $provider = new OpenAICompatibleChatProvider(
        (string)$settings['base_url'],
        $apiKey,
        (string)$settings['model']
    );

    for ($i = 0; $i < max(1, min(25, $batchSize)); $i++) {
        $lock = uuid_v4();
        $pdo->beginTransaction();
        try {
            $jobQuery = $pdo->prepare(
                'SELECT id, conversation_id, source_message_id, attempts
                 FROM ai_jobs
                 WHERE tenant_id = :tenant_id
                   AND ((status = "pending" AND available_at <= UTC_TIMESTAMP())
                     OR (status = "processing" AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)))
                 ORDER BY created_at ASC LIMIT 1 FOR UPDATE'
            );
            $jobQuery->execute([':tenant_id' => $tenantId]);
            $job = $jobQuery->fetch();
            if (!$job) {
                $pdo->commit();
                break;
            }
            $attempt = (int)$job['attempts'] + 1;
            $claim = $pdo->prepare(
                'UPDATE ai_jobs SET status = "processing", attempts = :attempt,
                    locked_at = UTC_TIMESTAMP(), lock_token = :lock
                 WHERE tenant_id = :tenant_id AND id = :id'
            );
            $claim->execute([':attempt' => $attempt, ':lock' => $lock, ':tenant_id' => $tenantId, ':id' => $job['id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }

        try {
            $handoffCheck = $pdo->prepare(
                'SELECT id FROM conversation_handoffs WHERE tenant_id = :tenant_id
                 AND conversation_id = :conversation_id AND status IN ("open", "assigned") LIMIT 1'
            );
            $handoffCheck->execute([':tenant_id' => $tenantId, ':conversation_id' => $job['conversation_id']]);
            if ($handoffCheck->fetchColumn()) {
                $cancel = $pdo->prepare('UPDATE ai_jobs SET status = "cancelled", locked_at = NULL, lock_token = NULL, updated_at = UTC_TIMESTAMP() WHERE tenant_id = :tenant_id AND id = :id');
                $cancel->execute([':tenant_id' => $tenantId, ':id' => $job['id']]);
                $summary['skipped']++;
                continue;
            }

            $source = $pdo->prepare('SELECT body FROM messages WHERE tenant_id = :tenant_id AND id = :id AND conversation_id = :conversation_id LIMIT 1');
            $source->execute([':tenant_id' => $tenantId, ':id' => $job['source_message_id'], ':conversation_id' => $job['conversation_id']]);
            $question = (string)$source->fetchColumn();
            $knowledge = chatbridge_retrieve_knowledge($pdo, $tenantId, $question);
            if ($knowledge === []) {
                chatbridge_create_handover($pdo, $tenantId, $job['conversation_id'], 'low_knowledge');
                $cancel = $pdo->prepare('UPDATE ai_jobs SET status = "cancelled", locked_at = NULL, lock_token = NULL, updated_at = UTC_TIMESTAMP() WHERE tenant_id = :tenant_id AND id = :id');
                $cancel->execute([':tenant_id' => $tenantId, ':id' => $job['id']]);
                $summary['handed_over']++;
                continue;
            }

            $context = [];
            foreach ($knowledge as $entry) {
                $context[] = '[' . $entry['title'] . '] ' . mb_substr_safe((string)$entry['content'], 0, 3000);
            }
            $historyQuery = $pdo->prepare(
                'SELECT sender_type, body FROM messages
                 WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id
                 ORDER BY sequence_id DESC LIMIT 12'
            );
            $historyQuery->execute([':tenant_id' => $tenantId, ':conversation_id' => $job['conversation_id']]);
            $history = array_reverse($historyQuery->fetchAll());
            $messages = [[
                'role' => 'system',
                'content' => "You are the customer support assistant for this business. Answer only using the business knowledge supplied below and the conversation context. If the knowledge does not answer the question, say you cannot confirm and ask for a human team member. Do not invent prices, policies, stock, delivery dates or order status. Never request passwords, payment card data or one-time codes.\n\nBUSINESS KNOWLEDGE:\n" . implode("\n\n", $context),
            ]];
            foreach ($history as $line) {
                $role = $line['sender_type'] === 'visitor' ? 'user' : 'assistant';
                $messages[] = ['role' => $role, 'content' => (string)$line['body']];
            }

            $result = $provider->generate($messages, (int)$settings['max_output_tokens']);
            $pdo->beginTransaction();
            $insertReply = $pdo->prepare(
                'INSERT INTO messages (id, tenant_id, conversation_id, sender_type, body, delivery_status, sent_at, created_at)
                 VALUES (:id, :tenant_id, :conversation_id, "business", :body, "web_only", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insertReply->execute([
                ':id' => uuid_v4(),
                ':tenant_id' => $tenantId,
                ':conversation_id' => $job['conversation_id'],
                ':body' => mb_substr_safe($result['content'], 0, 4000),
            ]);
            $usage = $pdo->prepare(
                'INSERT INTO ai_usage_events (tenant_id, provider, model, input_tokens, output_tokens, total_tokens, created_at)
                 VALUES (:tenant_id, :provider, :model, :input_tokens, :output_tokens, :total_tokens, UTC_TIMESTAMP())'
            );
            $usage->execute([
                ':tenant_id' => $tenantId,
                ':provider' => (string)$settings['provider'],
                ':model' => (string)$result['model'],
                ':input_tokens' => $result['input_tokens'],
                ':output_tokens' => $result['output_tokens'],
                ':total_tokens' => $result['input_tokens'] + $result['output_tokens'],
            ]);
            $done = $pdo->prepare('UPDATE ai_jobs SET status = "completed", locked_at = NULL, lock_token = NULL, updated_at = UTC_TIMESTAMP() WHERE tenant_id = :tenant_id AND id = :id AND lock_token = :lock');
            $done->execute([':tenant_id' => $tenantId, ':id' => $job['id'], ':lock' => $lock]);
            $pdo->commit();
            $summary['generated']++;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $shouldRetry = $attempt < $maxAttempts;
            $delay = min(900, 15 * (2 ** min(6, max(0, $attempt - 1))));
            $update = $pdo->prepare(
                'UPDATE ai_jobs SET status = :status, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :delay SECOND),
                 locked_at = NULL, lock_token = NULL, last_error = :error, updated_at = UTC_TIMESTAMP()
                 WHERE tenant_id = :tenant_id AND id = :id AND lock_token = :lock'
            );
            $update->execute([
                ':status' => $shouldRetry ? 'pending' : 'failed',
                ':delay' => $delay,
                ':error' => mb_substr_safe($error->getMessage(), 0, 450),
                ':tenant_id' => $tenantId,
                ':id' => $job['id'],
                ':lock' => $lock,
            ]);
            if (!$shouldRetry) {
                chatbridge_create_handover($pdo, $tenantId, $job['conversation_id'], 'ai_error');
                $summary['failed']++;
            }
            error_log('[ChatBridge AI] Job failed: ' . $error->getMessage());
        }
    }
    return $summary;
}
