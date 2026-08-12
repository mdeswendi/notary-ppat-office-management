<?php

namespace App\Domains\Party;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Models\Company;
use App\Models\Individual;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Advisory duplicate candidates for a Party the office is about to record or
 * change (D-084, D-086).
 *
 * **Advisory, and that word carries weight.** Nothing here blocks a create or an
 * update, merges anything, archives anything, or asserts that two records are
 * the same person or organization — that assertion is a human judgement the
 * software has no standing to make. The caller may read the warning and proceed;
 * an alternate client may skip the check entirely, and that is intentional.
 *
 * **Confined to the target Office, always.** Not to the actor's Office — to the
 * Office the record is being created in or already lives in. An `ALL`-scoped
 * actor checking a candidate for Office A compares against Office A and nothing
 * else. `ALL` permits working in another Office; it does not turn duplicate
 * detection into a deployment-wide identity registry, and a caller must never be
 * able to learn that an identifier exists somewhere they cannot see. The Office
 * filter and the actor's own visibility are both applied, so the narrower of the
 * two always wins.
 *
 * **Exact signals only.** No fuzzy matching, no Levenshtein, no trigram, no
 * phonetics, no score, no "95% likely". Every signal is a deterministic equality
 * test, and the response says which ones matched so a person can judge. A
 * similarity number would be a confidence claim, and confidence about identity
 * is exactly what M2 has no authority to express.
 *
 * **Sensitive signals compare fingerprints, never decrypted values.** The stored
 * ciphertext is randomized and unsearchable by design; comparing raw values
 * would mean decrypting the directory to answer one question. See
 * {@see IdentityFingerprint}.
 *
 * **Archived Parties are excluded.** The soft-delete scope sees to it, and that
 * is deliberate: broadening visibility of retired records to improve matching
 * would disclose identities the office has withdrawn from the directory.
 */
class DuplicateCandidateFinder
{
    /**
     * A deliberately small, deterministic cap. Duplicate assistance is a prompt
     * for review; a hundred candidates is not a prompt, it is a report nobody
     * reads — and a large result set is a better oracle than a small one.
     */
    public const LIMIT = 10;

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PartyVisibility $visibility,
        private readonly IdentityFingerprint $fingerprint,
    ) {}

    /**
     * May this actor be told about sensitive matches on this field, in this
     * Office?
     *
     * Field-specific: `parties.identity.nik.view_full` for NIK,
     * `parties.identity.npwp.view_full` for NPWP and Company `tax_id` — the same
     * canonical codes the reveal surfaces use (D-082). `parties.identity.update`
     * is deliberately not accepted: writing a value is not licence to learn that
     * somebody else already has it.
     *
     * Confirming "a record with this NIK exists here" is a disclosure about that
     * record, so it answers to the permission that governs the identifier rather
     * than to the one that governs the directory entry.
     */
    public function mayUseSensitiveSignal(User $actor, string $permission, string $officeId): bool
    {
        $access = $this->resolver->resolve($actor, $permission);

        if (! $access->granted) {
            return false;
        }

        return $this->visibility->reachesAllOffices($access) || $actor->office_id === $officeId;
    }

    /**
     * Individual candidates in the target Office.
     *
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, array<string, mixed>>
     */
    public function forIndividual(
        User $actor,
        string $officeId,
        array $attributes,
        ?string $excludePartyId = null,
    ): Collection {
        $parties = $this->reachableParties($actor, 'parties.view', 'INDIVIDUAL', $officeId, $excludePartyId);

        if ($parties === null) {
            return collect();
        }

        $signals = [];

        $this->addFingerprintSignal($signals, 'NIK_EXACT', 'nik_fingerprint', $attributes['nik'] ?? null);
        $this->addFingerprintSignal($signals, 'NPWP_EXACT', 'npwp_fingerprint', $attributes['npwp'] ?? null);

        $matches = collect();

        foreach ($signals as $signal => $condition) {
            $this->collect($matches, $signal, Individual::query()
                ->whereIn('party_id', $parties->clone()->select('parties.id'))
                ->where($condition[0], $condition[1])
                ->pluck('party_id'));
        }

        // Ordinary contact signals live on the Party root.
        $this->collect($matches, 'EMAIL_EXACT',
            $this->partyFieldMatch($parties, 'primary_email', $attributes['primary_email'] ?? null, true));
        $this->collect($matches, 'PHONE_EXACT',
            $this->partyFieldMatch($parties, 'primary_phone', $attributes['primary_phone'] ?? null, false));

        // Name plus date of birth, together. Either alone is far too common to
        // be a useful hint, and a name alone would make the check a directory
        // search.
        $name = self::normalizeName((string) ($attributes['full_name'] ?? ''));
        $birthDate = trim((string) ($attributes['birth_date'] ?? ''));

        if ($name !== '' && $birthDate !== '') {
            $this->collect($matches, 'NAME_BIRTH_DATE_EXACT', Individual::query()
                ->whereIn('party_id', $parties->clone()->select('parties.id'))
                ->whereRaw(self::collapsedLower('full_name').' = ?', [$name])
                ->whereDate('birth_date', $birthDate)
                ->pluck('party_id'));
        }

        return $this->hydrate($matches, 'INDIVIDUAL');
    }

    /**
     * Company candidates in the target Office.
     *
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, array<string, mixed>>
     */
    public function forCompany(
        User $actor,
        string $officeId,
        array $attributes,
        ?string $excludePartyId = null,
    ): Collection {
        $parties = $this->reachableParties($actor, 'companies.view', 'COMPANY', $officeId, $excludePartyId);

        if ($parties === null) {
            return collect();
        }

        $matches = collect();

        $taxFingerprint = $this->fingerprint->of(
            ($attributes['tax_id'] ?? null) === null ? null : (string) $attributes['tax_id']
        );

        if ($taxFingerprint !== null) {
            $this->collect($matches, 'TAX_ID_EXACT', Company::query()
                ->whereIn('party_id', $parties->clone()->select('parties.id'))
                ->where('tax_id_fingerprint', $taxFingerprint)
                ->pluck('party_id'));
        }

        $registration = trim((string) ($attributes['registration_number'] ?? ''));

        if ($registration !== '') {
            $this->collect($matches, 'REGISTRATION_NUMBER_EXACT', Company::query()
                ->whereIn('party_id', $parties->clone()->select('parties.id'))
                ->whereRaw('lower(trim(registration_number)) = ?', [mb_strtolower($registration)])
                ->pluck('party_id'));
        }

        $legalName = self::normalizeName((string) ($attributes['legal_name'] ?? ''));

        if ($legalName !== '') {
            $this->collect($matches, 'LEGAL_NAME_EXACT', Company::query()
                ->whereIn('party_id', $parties->clone()->select('parties.id'))
                ->whereRaw(self::collapsedLower('legal_name').' = ?', [$legalName])
                ->pluck('party_id'));
        }

        $this->collect($matches, 'EMAIL_EXACT',
            $this->partyFieldMatch($parties, 'primary_email', $attributes['primary_email'] ?? null, true));
        $this->collect($matches, 'PHONE_EXACT',
            $this->partyFieldMatch($parties, 'primary_phone', $attributes['primary_phone'] ?? null, false));

        return $this->hydrate($matches, 'COMPANY');
    }

    /**
     * The Party rows this actor may be told about, in this Office.
     *
     * Both constraints applied together — the actor's Data Scope **and** the
     * target Office — so an `ALL` actor is still confined to the target and an
     * `OFFICE` actor from elsewhere reaches nothing. Returns null when the actor
     * holds no usable scope at all, which is how "no candidates" and "not
     * entitled to know" produce the same empty answer.
     *
     * @return Builder<Party>|null
     */
    private function reachableParties(
        User $actor,
        string $permission,
        string $partyType,
        string $officeId,
        ?string $excludePartyId,
    ): ?Builder {
        $access = $this->resolver->resolve($actor, $permission);

        if (! $this->visibility->hasUsableScope($access)) {
            return null;
        }

        $query = $this->visibility->scope(
            Party::query()->where('party_type', $partyType)->where('office_id', $officeId),
            $actor,
            $access,
        );

        // Server-side exclusion of the record being edited. Never a
        // client-supplied id: accepting one would let a caller suppress an
        // inconvenient candidate from somebody else's review.
        if ($excludePartyId !== null) {
            $query->whereKeyNot($excludePartyId);
        }

        return $query;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $signals
     */
    private function addFingerprintSignal(array &$signals, string $name, string $column, mixed $value): void
    {
        $fingerprint = $this->fingerprint->of($value === null ? null : (string) $value);

        if ($fingerprint !== null) {
            $signals[$name] = [$column, $fingerprint];
        }
    }

    /**
     * @param  Builder<Party>  $parties
     * @return Collection<int, string>
     */
    private function partyFieldMatch(Builder $parties, string $column, mixed $value, bool $caseInsensitive): Collection
    {
        $trimmed = trim((string) ($value ?? ''));

        if ($trimmed === '') {
            return collect();
        }

        $query = $parties->clone();

        // Conservative and advisory: case folding for email, trim only for
        // phone. No international telephone normalization is attempted and no
        // dependency is introduced for one — a rewritten number is a changed
        // number, and guessing the format is how a match becomes wrong.
        $caseInsensitive
            ? $query->whereRaw("lower(trim({$column})) = ?", [mb_strtolower($trimmed)])
            : $query->whereRaw("trim({$column}) = ?", [$trimmed]);

        return $query->pluck('parties.id');
    }

    /**
     * @param  Collection<string, array<int, string>>  $matches
     * @param  Collection<int, string>  $ids
     */
    private function collect(Collection $matches, string $signal, Collection $ids): void
    {
        foreach ($ids as $id) {
            $matches[$id] = array_values(array_unique([...($matches[$id] ?? []), $signal]));
        }
    }

    /**
     * Turn matched ids into the minimal candidate shape.
     *
     * @param  Collection<string, array<int, string>>  $matches
     * @return Collection<int, array<string, mixed>>
     */
    private function hydrate(Collection $matches, string $partyType): Collection
    {
        if ($matches->isEmpty()) {
            return collect();
        }

        $parties = Party::query()
            ->with('office')
            ->whereIn('id', $matches->keys())
            ->orderBy('display_name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        return $parties->map(fn (Party $party): array => [
            'id' => $party->getKey(),
            'party_type' => $partyType,
            'display_name' => $party->display_name,
            'office' => $party->office === null ? null : [
                'id' => $party->office->id,
                'code' => $party->office->code,
                'name' => $party->office->name,
            ],
            // Which tests matched, and nothing about the values that matched.
            'signals' => $matches[$party->getKey()],
        ])->values();
    }

    /**
     * Trim, collapse runs of ordinary whitespace, and fold case.
     *
     * Presentation normalization for an advisory comparison — not a claim that
     * two spellings are the same legal name.
     */
    private static function normalizeName(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', trim($value))));
    }

    /**
     * The same normalization, expressed in portable SQL.
     *
     * `trim` and nested `replace` rather than `btrim` and `regexp_replace`,
     * because the product runs on PostgreSQL while the test suite runs on
     * SQLite — a PostgreSQL-only expression here would leave this comparison
     * untestable, which is worse than the small inelegance. Three passes of
     * `replace` collapse up to eight consecutive spaces, well past anything a
     * real name contains.
     */
    private static function collapsedLower(string $column): string
    {
        $expression = "trim({$column})";

        for ($pass = 0; $pass < 3; $pass++) {
            $expression = "replace({$expression}, '  ', ' ')";
        }

        return "lower({$expression})";
    }
}
