<?php

namespace App\Models;

use App\Domains\Audit\Enums\AuditEvent;
use App\Domains\Audit\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One immutable record of who changed what (M8.1, D-123, closing D-115).
 *
 * ## Append-only is enforced here, not merely intended
 *
 * `CLAUDE.md` section 31 states that audit records are immutable from the
 * application and that `audit.update` and `audit.delete` must never exist.
 * `03_DATABASE_ERD.md` section 25 says the same structurally by giving the table
 * no `updated_at` and no `deleted_at`.
 *
 * This model closes the remaining path: `updating` and `deleting` both throw, so
 * there is **no internal method that could perform one** — not a service that
 * forgets, not a future controller, not a careless `save()` on a hydrated row. A
 * convention holds only while everybody remembers it; a thrown exception holds
 * regardless.
 *
 * `const UPDATED_AT = null` is what keeps Eloquent from writing a column that does
 * not exist.
 *
 * ## What must never be written here
 *
 * `old_values` and `new_values` describe *that* something changed. For a masked
 * field they must not carry the value — D-105's leak-surface rule, which D-115
 * restates with more force. {@see AuditLogger} is the
 * only writer and holds the redaction list; nothing should construct this model
 * directly.
 *
 * ## Reading it requires `audit.view`
 *
 * Unlike {@see Activity}, which has no capability and is read through its
 * subject's visibility (O-047), this table answers to a registered capability.
 * That difference is the reason both tables exist — see the `activities`
 * migration for the authorization argument.
 */
class AuditLog extends Model
{
    use HasUlids;

    /**
     * There is no `updated_at`, and there never will be.
     */
    public const UPDATED_AT = null;

    /**
     * **Nothing is fillable.** Every row is written by
     * {@see AuditLogger} through an explicit
     * attribute list, so mass assignment has no legitimate caller here.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::updating(static function (self $log): void {
            throw new RuntimeException(
                'audit_logs is append-only (CLAUDE.md section 31, D-123). '
                .'An audit record cannot be amended: correct the world, then record that correction '
                .'as a new event. Log id: '.($log->getKey() ?? 'unsaved')
            );
        });

        static::deleting(static function (self $log): void {
            throw new RuntimeException(
                'audit_logs is append-only (CLAUDE.md section 31, D-123). '
                .'Audit records are never removed, and no retention policy exists yet. '
                .'Log id: '.($log->getKey() ?? 'unsaved')
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForAuditable(Builder $query, string $type, string $id): Builder
    {
        return $query->where('auditable_type', $type)->where('auditable_id', $id);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeByEvent(Builder $query, AuditEvent|string $event): Builder
    {
        return $query->where('event', $event instanceof AuditEvent ? $event->value : $event);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeByDateRange(Builder $query, ?string $from, ?string $until): Builder
    {
        return $query
            ->when($from !== null, fn (Builder $q): Builder => $q->where('created_at', '>=', $from))
            ->when($until !== null, fn (Builder $q): Builder => $q->where('created_at', '<=', $until));
    }
}
