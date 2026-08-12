<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminActionLog extends Model
{
    protected $fillable = [
        'admin_id', 'action', 'target_type', 'target_id', 'meta', 'ip_address',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Record an admin action. Kept as a static helper so call sites stay
     * a single line — see app/Http/Controllers for usage.
     */
    public static function record(
        User $admin,
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        array $meta = [],
        ?string $ip = null
    ): self {
        return static::create([
            'admin_id'    => $admin->id,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'meta'        => $meta,
            'ip_address'  => $ip,
        ]);
    }
}
