<?php

namespace App\Models;

use App\Domains\Party\Enums\CompanyEntityType;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The organization subtype of Party (D-078).
 *
 * Structurally the mirror of {@see Individual}: keyed by `party_id`, no surrogate
 * id, no second public identifier.
 *
 * **`tax_id` is the Company NPWP and is hidden and encrypted**, exactly as the
 * Individual identifiers are (D-082). A corporate tax identifier is no less
 * sensitive for belonging to an organization, and `companies.view` grants no
 * sight of it — reveal answers to `parties.identity.npwp.view_full`, the same
 * canonical code, because the identity surface belongs to the aggregate. No
 * `companies.identity.*` family exists.
 *
 * `phone` and `email` are deliberately absent: they duplicated
 * `parties.primary_phone` and `parties.primary_email` with no independent
 * meaning, and `individuals` never carried a pair. So is `status`, which
 * competed with `parties.deleted_at` for archive authority (D-081).
 */
#[Fillable([
    'legal_name',
    'short_name',
    'entity_type',
    'registration_number',
    'tax_id',
    'address',
    'village',
    'district',
    'city',
    'province',
    'postal_code',
])]
#[Hidden(['tax_id', 'party_type'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $primaryKey = 'party_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Resolve only live Companies from a route.
     *
     * The mirror of {@see Individual::resolveRouteBindingQuery()}, and two things
     * fall out of joining to a non-archived Party, both deliberate:
     *
     * An **archived** Company becomes unreachable through every ordinary route —
     * list, detail, update, identity, and reveal all 404 rather than operating on
     * a record the office has retired (D-081).
     *
     * An **Individual** Party id used on a Company route also 404s, because no
     * `companies` row exists for it. That is the right answer rather than 403:
     * telling the caller "wrong type" would confirm a record exists in a
     * namespace they were not asking about, and possibly in an Office they
     * cannot see.
     *
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query
            ->where($this->getRouteKeyName(), $value)
            ->whereHas('party');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    /**
     * Every relationship this company has held, current and historical.
     *
     * @return HasMany<CompanyPerson, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(CompanyPerson::class, 'company_party_id', 'party_id');
    }

    /**
     * The display name this Company contributes to its Party (D-079).
     *
     * Short name when one was intentionally recorded, otherwise the legal name.
     * A short name exists precisely because somebody wanted the organization
     * displayed that way. Read-only here; the Actions that M2.3 introduces own
     * writing it to the Party, atomically.
     */
    public function preferredDisplayName(): string
    {
        $short = trim((string) $this->short_name);

        return $short !== '' ? $short : $this->legal_name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_id' => 'encrypted',
            'entity_type' => CompanyEntityType::class,
        ];
    }
}
