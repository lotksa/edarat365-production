<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SecurityAuditLog extends Model
{
    public $timestamps = false;
    protected $table = 'security_audit_log';

    protected $fillable = [
        'event', 'actor_user_id', 'actor_identifier',
        'subject_type', 'subject_id', 'ip_address', 'user_agent',
        'context', 'outcome', 'created_at',
    ];

    protected $casts = [
        'context'    => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Convenience helper for emitting audit events.
     *
     * @param string                $event    e.g. "auth.login.success"
     * @param string                $outcome  "success" or "failed"
     * @param array<string,mixed>   $context  Free-form details (avoid full PII; mask values)
     * @param User|int|null         $actor    The user performing the action
     * @param string|null           $actorIdentifier email/phone if no User
     * @param array{type?:string,id?:int}|null $subject Optional subject ref
     */
    public static function record(
        string $event,
        string $outcome = 'success',
        array $context = [],
        $actor = null,
        ?string $actorIdentifier = null,
        ?array $subject = null,
        ?Request $request = null
    ): void {
        try {
            $request = $request ?? request();
            self::create([
                'event'            => $event,
                'outcome'          => $outcome,
                'actor_user_id'    => $actor instanceof User ? $actor->id : (is_int($actor) ? $actor : null),
                'actor_identifier' => $actorIdentifier ? self::maskIdentifier($actorIdentifier) : null,
                'subject_type'     => $subject['type'] ?? null,
                'subject_id'       => $subject['id'] ?? null,
                'ip_address'       => $request?->ip(),
                'user_agent'       => substr((string) $request?->userAgent(), 0, 512),
                'context'          => $context,
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SecurityAuditLog write failed: ' . $e->getMessage());
        }
    }

    private static function maskIdentifier(string $id): string
    {
        if (str_contains($id, '@')) {
            [$name, $domain] = explode('@', $id, 2);
            $name = strlen($name) <= 2 ? $name : substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 2));
            return $name . '@' . $domain;
        }
        return strlen($id) <= 4 ? str_repeat('*', strlen($id)) : str_repeat('*', strlen($id) - 4) . substr($id, -4);
    }
}
