<?php

namespace App\Http\Resources;

use App\Domains\Audit\Services\AuditLogger;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit row, as an auditor reads it (M8.1, D-123).
 *
 * `old_values` and `new_values` ship as stored — which means they ship already
 * redacted, because {@see AuditLogger} withholds
 * sensitive values before writing rather than at serialization. That ordering is
 * deliberate: a resource that redacted on the way out would leave the raw value
 * sitting in a table that nobody may delete from, and one forgotten `->toArray()`
 * elsewhere would disclose it.
 *
 * `ip_address` and `user_agent` are included. They are exactly the material an
 * auditor needs to tell a routine action from an anomalous one, and
 * `CLAUDE.md` section 31 names both as audit fields.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,

            'auditable_type' => class_basename($this->auditable_type),
            'auditable_id' => $this->auditable_id,

            'old_values' => $this->old_values,
            'new_values' => $this->new_values,

            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'reason' => $this->reason,

            'actor' => $this->whenLoaded('actor', fn (): ?array => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
