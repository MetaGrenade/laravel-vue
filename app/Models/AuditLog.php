<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One thing somebody did in the admin area (see docs/audit-log.md). The log only grows: a row is written once and never changed or
 * removed by the application. The only thing that deletes rows is the daily `PruneAuditLogs` job, which goes around the model to the
 * table, once they are older than `audit.retention_days`.
 *
 * (This guards the application's own code. Whoever can write to the database can still change it; to stop that too, give the
 * application's database user no UPDATE or DELETE on this table, and run pruning as another user.)
 */
class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const DENIED = 'denied';

    /** @var list<string> */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('The audit log is append-only: an entry is never changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('The audit log is append-only: an entry is never deleted (PruneAuditLogs removes old ones, at the table).');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
