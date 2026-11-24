<?php
declare(strict_types=1);

/** Provider-neutral surface used by the Meta Embedded Signup application service. */
interface MetaGraphApi
{
    /** @return array<string,mixed> */
    public function get(string $url): array;

    public function post(string $url, string $token): bool;
}
