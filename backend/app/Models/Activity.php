<?php

namespace App\Models;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Activity\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One entry on a timeline (M8.1, D-123).
 *
 * ## This model has no capability, and that is the design
 *
 * There is no `activity.view` code in the registry and none is needed (O-047).
 * An Activity is read **per row, by the visibility of its subject**: a row about a
 * Matter is visible exactly when that Matter is, and a row whose subject the actor
 * cannot reach is absent rather than redacted — D-098's treatment of unreachable
 * records, applied to a feed.
 *
 * That is what distinguishes it from {@see AuditLog}, which answers to
 * `audit.view`. The distinction is not stylistic: a dashboard feed reading from
 * `audit_logs` would either be denied to every actor without the audit capability,
 * or would become a way to read audit content without holding it, which D-122
 * forbids by name.
 *
 * ## Written by the system, never by a user
 *
 * There is no `POST /api/v1/activities` and no create endpoint of any kind.
 * {@see ActivityRecorder} is the only writer. If a
 * user-authored timeline note is ever wanted, that is a new capability and a
 * catalogue extension — the question O-047 holds open.
 *
 * ## Not backfilled
 *
 * Seven milestones of work happened before this table existed and those events
 * were not recorded. D-123 forbids manufacturing rows for them: the feed starts
 * empty and fills forward, and an empty state on the day M8.1 ships is expected
 * behaviour rather than a defect.
 */
class Activity extends Model
{
    use HasUlids;

    /**
     * The ERD gives this table `created_at` alone.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * A timeline entry describes a moment that either happened or did not.
     *
     * Not the append-only *rule* `audit_logs` carries — the ERD states that only
     * for audit — but the field list says the same thing, and there is nothing
     * about a past event to amend or withdraw.
     */
    protected static function booted(): void
    {
        static::updating(static function (self $activity): void {
            throw new RuntimeException(
                'activities is append-only (D-123). A timeline entry records a moment; '
                .'it is not amended. Activity id: '.($activity->getKey() ?? 'unsaved')
            );
        });

        static::deleting(static function (self $activity): void {
            throw new RuntimeException(
                'activities is append-only (D-123). Activity id: '.($activity->getKey() ?? 'unsaved')
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_type' => ActivityType::class,
            'metadata' => 'array',
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForSubject(Builder $query, string $type, string $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }
}
