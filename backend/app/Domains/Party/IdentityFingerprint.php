<?php

namespace App\Domains\Party;

use Illuminate\Contracts\Encryption\Encrypter;
use SensitiveParameter;

/**
 * A keyed blind fingerprint for a sensitive identifier (D-086).
 *
 * **The problem this solves.** NIK, NPWP, and Company `tax_id` are stored with
 * Laravel's `encrypted` cast, which is randomized: the same NIK encrypted twice
 * produces two different ciphertexts. That is correct for confidentiality and
 * fatal for equality search — `WHERE nik = ?` can never match, and the
 * alternatives are all worse. Decrypting every row to compare in PHP does not
 * scale and puts every identifier in the office's memory to answer one question.
 * A plaintext copy defeats the encryption entirely. An unkeyed hash is
 * brute-forceable in seconds: a NIK has 10^16 possibilities and a GPU does not
 * find that difficult (D-082 says so explicitly).
 *
 * **The construction.**
 *
 * ```text
 * subkey      = HKDF-SHA-256(APP_KEY material, 32 bytes,
 *                            info = "notary-ppat/party-identity-fingerprint/v1")
 * fingerprint = HMAC-SHA-256(conservatively normalized value, subkey)  -> 64 hex
 * ```
 *
 * Keyed, so an attacker holding a database dump cannot enumerate candidate
 * identifiers offline without also holding the application key. Derived rather
 * than reusing `APP_KEY` directly, so this purpose is domain-separated from
 * encryption: a weakness or leak in one use does not hand over the other, and
 * the versioned context string leaves room to re-derive without changing the
 * key. Standard primitives only — `hash_hkdf` and `hash_hmac`, both in PHP's
 * core — because M1.9 refused to hand-roll TOTP for the same reason.
 *
 * **Rotating `APP_KEY` invalidates every stored fingerprint.** They are derived
 * from it, so a rotation must be followed by
 * `php artisan parties:rebuild-identity-fingerprints`. Until that runs, duplicate
 * detection under-reports — which is the safe direction to fail, but it is not
 * silent to anyone reading this.
 *
 * **This is not identity proof.** A fingerprint says two records carry byte-equal
 * normalized identifiers. It does not say two records are the same person; that
 * assertion belongs to a human (D-084).
 */
final class IdentityFingerprint
{
    /**
     * Domain separation for the derived subkey.
     *
     * Versioned deliberately. If the construction ever needs to change, `v2`
     * derives a different subkey from the same `APP_KEY`, and the maintenance
     * command rebuilds — no key rotation and no second secret required.
     */
    private const CONTEXT = 'notary-ppat/party-identity-fingerprint/v1';

    private const SUBKEY_BYTES = 32;

    /**
     * Memoized per instance. Deriving costs little, but the value is key
     * material and there is no reason to recompute it once per row.
     */
    private ?string $subkey = null;

    public function __construct(private readonly Encrypter $encrypter) {}

    /**
     * The fingerprint of a raw identifier, or null when there is nothing to
     * fingerprint.
     *
     * Null in, null out — an absent identifier must not acquire a fingerprint,
     * because a shared "fingerprint of empty" would make every record with no
     * NIK a duplicate of every other.
     */
    public function of(#[SensitiveParameter] ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = self::normalize($value);

        if ($normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, $this->subkey());
    }

    /**
     * Conservative normalization, and deliberately nothing more.
     *
     * Surrounding whitespace is removed because it is an artifact of typing, not
     * of the identifier. **Everything else is preserved exactly**: leading zeros,
     * internal punctuation, letter case, and every remaining character.
     *
     * So `09.123.456.7-890.123` and `091234567890123` produce **different**
     * fingerprints and will not match each other. That is a known, accepted
     * false negative rather than an oversight. No canonical document in this
     * repository defines legal NIK or NPWP normalization; Indonesian NPWP
     * formats have changed, and a guess encoded here would silently assert that
     * two identifiers are equivalent on an authority this milestone does not
     * have. Duplicate detection is advisory (D-084), so under-reporting costs a
     * missed hint while over-reporting would claim an equivalence nobody
     * approved. Missing a match is the safe direction.
     */
    public static function normalize(#[SensitiveParameter] string $value): string
    {
        return trim($value);
    }

    private function subkey(): string
    {
        return $this->subkey ??= hash_hkdf(
            'sha256',
            $this->encrypter->getKey(),
            self::SUBKEY_BYTES,
            self::CONTEXT,
        );
    }
}
