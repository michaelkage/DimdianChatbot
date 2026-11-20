# Tenant AI, business knowledge and human handover

The AI layer is optional and disabled for every tenant until explicitly configured. It does not assume a provider, model, API key or budget for a business.

## Architecture

- `ChatBridgeAIProvider` is the provider interface.
- `OpenAICompatibleChatProvider` is the first adapter for providers implementing the Chat Completions-compatible HTTPS contract. Other providers can implement the same interface.
- Provider API keys are encrypted with AES-256-GCM before they are stored in `tenant_ai_settings`. The master encryption key is configured outside MySQL in private `config.php`.
- `knowledge_sources` stores tenant-owned FAQ, policy, product, page and document text. Retrieval is filtered by tenant ID and uses the MySQL full-text index.
- `ai_jobs` queues work outside the visitor request. The worker loads a tenant's provider configuration, retrieves that tenant's knowledge and writes a business reply into the same conversation.
- `conversation_handoffs` records explicit visitor requests, low-knowledge cases, AI errors and budget exhaustion. A single active handover per conversation is enforced by a unique nullable active-conversation key; resolution must clear that key when a handover is closed.
- `ai_usage_events` records provider/model token usage for the tenant. If the provider omits token counts, the adapter records a rough character-based estimate.

## Setup

1. Apply migration 004, then migration 005.
2. Set a persistent 32-byte `app.encryption_key` in private `config.php`. Generate it once with:

   ```sh
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

   Back it up securely. Losing this key makes previously encrypted provider credentials unreadable. Never rotate it without a credential re-encryption plan.
3. Configure the provider from a CLI environment variable so the API key is not placed in shell arguments or source control:

   ```sh
   CHATBRIDGE_AI_API_KEY='...' php bin/configure-ai.php TENANT_UUID MODEL https://provider.example/v1 100000
   ```

   The last argument is the tenant's monthly token budget; use `0` for no configured limit. The provider is stored as `openai_compatible`; the adapter requires HTTPS.
4. Import approved business knowledge as plain UTF-8 text (maximum 100 KB per source):

   ```sh
   php bin/import-knowledge.php TENANT_UUID policy "Returns and refunds" /secure/path/returns-policy.txt
   ```
5. Schedule the tenant-scoped worker:

   ```sh
   php /absolute/path/to/project/public/cron/process-ai.php TENANT_UUID
   ```

   Run one worker per tenant at a time. The token budget gate is designed for a single serialized worker per tenant; do not run concurrent workers for the same tenant until atomic budget reservation is added.

## Behavior and safety

- If AI is disabled, the message remains a normal website chat message.
- If the visitor explicitly asks for a human, an open handover is created and no AI job is queued.
- If an active handover already exists, further AI jobs are not queued.
- If no published knowledge matches, the worker opens a `low_knowledge` handover rather than guessing business facts.
- The system prompt requires the model to answer only from supplied tenant knowledge and conversation context, and not invent prices, policies, stock, delivery dates or order status.
- AI replies are saved as business messages in website chat. WhatsApp delivery of AI replies is not implemented.
- Handover records are persisted and surfaced as a notice in the widget. A staffed inbox, assignment UI, agent notifications, and resolution controls are not implemented yet.
- Do not import passwords, access tokens, payment card data, private customer records or unnecessary sensitive data into the knowledge base.

This is the initial provider/knowledge runtime, not a complete AI support product. There is no provider-specific function calling, embeddings/vector search, document OCR, automatic WooCommerce product synchronization, provider billing integration, cost-in-currency accounting, or staff inbox UI yet.
