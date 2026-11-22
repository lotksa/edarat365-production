<?php

namespace App\Services;

/** Request-local workspace. Never stored on the user, token, session or worker. */
final class AssociationContext
{
    public const OWNERS = 'owners';
    public const ENDOWMENTS = 'endowments';

    public ?int $id = null;
    public ?string $section = null;

    public function isEndowments(): bool
    {
        return $this->section === self::ENDOWMENTS;
    }
}
