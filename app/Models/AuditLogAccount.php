<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An account an audit log entry concerns (see docs/audit-log.md): a bulk action concerns every account it touched, not only the first.
 * It goes when its entry goes, and is never changed.
 */
class AuditLogAccount extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    // No key of its own: an account is listed once per entry (the table's unique index), and a row is only ever inserted or removed with its entry.
    protected $primaryKey = null;

    /** @var list<string> */
    protected $guarded = [];
}
