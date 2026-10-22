<?php

namespace App\Services;

/** Request-local workspace. Never stored on the user, token, session or worker. */
final class AssociationContext
{
    public ?int $id = null;
}
