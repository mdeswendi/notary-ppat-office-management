<?php

namespace App\Models;

use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An operating location belonging to exactly one Organization (D-027).
 *
 * `organization_id` and `is_active` are intentionally not fillable: reparenting
 * an Office or retiring it are administrative operations that must go through
 * an authorized action, never through mass assignment — see
 * docs/07_SECURITY_RULES.md section 34.
 */
#[Fillable([
    'code',
    'name',
    'address',
    'city',
    'province',
    'postal_code',
    'phone',
    'email',
    'timezone',
])]
class Office extends Model
{
    /** @use HasFactory<OfficeFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Users whose primary office this is. There is no membership pivot — see
     * D-027.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
