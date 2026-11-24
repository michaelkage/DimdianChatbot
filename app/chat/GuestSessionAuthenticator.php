<?php
declare(strict_types=1);

final class GuestSessionTokenInvalid extends RuntimeException
{
}

final class GuestSessionNotFound extends RuntimeException
{
}

final class GuestConversationClosed extends RuntimeException
{
}

/**
 * Authenticates guest tokens without reading request globals or emitting HTTP.
 *
 * The HTTP adapter extracts the token and maps these domain failures to the
 * established API error responses.
 */
final class GuestSessionAuthenticator
{
    private GuestSessionRepository $repository;

    public function __construct(GuestSessionRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @return array<string,mixed> */
    public function authenticate(string $token, TenantContext $tenantContext, bool $requireOpen = true): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new GuestSessionTokenInvalid('A valid chat session token is required.');
        }

        $session = $this->repository->findGuestSessionByTokenHash(hash('sha256', $token));
        if ($session === null) {
            throw new GuestSessionNotFound('This chat session is no longer valid. Start a new chat to continue.');
        }

        $this->repository->touchGuestSession((string) $session['session_id']);

        if ($requireOpen && ($session['conversation_status'] ?? null) !== 'open') {
            throw new GuestConversationClosed('This conversation has been closed. Start a new chat to send another message.');
        }

        return $session;
    }
}
