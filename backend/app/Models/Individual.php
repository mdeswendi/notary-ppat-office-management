<?php

namespace App\Models;

use Database\Factories\IndividualFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The person subtype of Party (D-078).
 *
 * Keyed by `party_id`, which is both primary key and foreign key — there is no
 * surrogate id and no second public identifier. The API addresses an Individual
 * by its Party ULID.
 *
 * **`nik` and `npwp` are hidden at the model** (D-082). Ordinary
 * `toArray()`/`toJson()` must never carry a raw identifier, because the moment
 * one does, it is in a log line, a cache entry, a debug dump, and any response
 * that serialized the model without thinking. Authorized reveal is an explicit
 * act performed by a Resource that reads the attribute deliberately — and that
 * Resource does not exist yet; M2.1 builds no endpoint.
 *
 * Both are `encrypted` casts, so the stored value is ciphertext and the plaintext
 * exists only in memory. That is Laravel's own primitive; no cryptography is
 * written here, for the reason M1.9 refused to hand-roll TOTP.
 *
 * `party_type` is not fillable and not meaningful as data — it is the pinned
 * column completing the composite foreign key that keeps this row's kind and its
 * Party's `party_type` in agreement.
 */
#[Fillable([
    'full_name',
    'prefix',
    'suffix',
    'nik',
    'npwp',
    'birth_place',
    'birth_date',
    'gender',
    'occupation',
    'nationality',
    'marital_status',
    'address',
    'village',
    'district',
    'city',
    'province',
    'postal_code',
])]
/*
 * `nik_fingerprint` and `npwp_fingerprint` are hidden and **not fillable**
 * (D-086). They are internal metadata derived from the identifiers, written only
 * by the identity Actions and the maintenance command, and disclosed to nobody —
 * not even to a holder of the full-view reveal permission, which authorizes the
 * identifier through the reviewed reveal surface rather than the cryptographic
 * material derived from it.
 */
#[Hidden(['nik', 'npwp', 'nik_fingerprint', 'npwp_fingerprint', 'party_type'])]
class Individual extends Model
{
    /** @use HasFactory<IndividualFactory> */
    use HasFactory;

    protected $primaryKey = 'party_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Resolve only live Individuals from a route.
     *
     * Two things fall out of joining to a non-archived Party, and both are
     * deliberate:
     *
     * An **archived** Individual becomes unreachable through every ordinary
     * route — list, detail, update, identity, and reveal all 404 rather than
     * operating on a record the office has retired (D-081).
     *
     * A **Company** Party id used on an Individual route also 404s, because no
     * `individuals` row exists for it. That is the right answer rather than 403:
     * telling the caller "wrong type" would confirm a record exists in a
     * namespace they were not asking about, and possibly in an Office they
     * cannot see.
     *
     * @param  Builder<Individual>  $query
     * @return Builder<Individual>
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
     * The companies this person is related to, across history.
     *
     * @return HasMany<CompanyPerson, $this>
     */
    public function companyRelationships(): HasMany
    {
        return $this->hasMany(CompanyPerson::class, 'individual_party_id', 'party_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Framework encryption. A database dump yields ciphertext, not
            // identity documents.
            'nik' => 'encrypted',
            'npwp' => 'encrypted',
            'birth_date' => 'date',
        ];
    }
}
