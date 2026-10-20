<?php

namespace App\Services\Realname;

interface IdentityProvider
{
    public function assertReady(): void;

    /** Returns only {accepted: bool, certify_id: ?string}; never raw provider responses. */
    public function initialize(string $outerOrderNo, #[\SensitiveParameter] string $name, #[\SensitiveParameter] string $idNumber): array;

    public function certify(#[\SensitiveParameter] string $certifyId): string;

    /** One of passed, failed, expired, unknown. Only authoritative signed responses are terminal. */
    public function query(#[\SensitiveParameter] string $certifyId): string;
}
