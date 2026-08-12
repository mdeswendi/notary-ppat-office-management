<?php

namespace App\Console\Commands;

use App\Domains\Party\IdentityFingerprint;
use App\Models\Company;
use App\Models\Individual;
use Illuminate\Console\Command;

/**
 * Rebuild the keyed identity fingerprints (D-086).
 *
 * Adding the columns does not populate them. Every identifier recorded during
 * M2.2 and M2.3 is encrypted and has no fingerprint, so without this command
 * duplicate detection would silently see none of the existing directory — and
 * re-entering the values by hand is not an option, since nobody can read them
 * back in bulk and should not have to.
 *
 * Also the answer to a rotated `APP_KEY`: fingerprints derive from it, so a
 * rotation invalidates all of them and this command is what restores them.
 *
 * **Idempotent.** It recomputes from the decrypted value and writes the result,
 * so running it twice produces the same state as running it once, and running it
 * against a half-populated table finishes the job. There is no "already done"
 * flag to get wrong.
 *
 * **It prints counts and nothing else.** No identifier, no fingerprint, no
 * record identifier reaches the terminal or the log — a maintenance command that
 * echoed what it was working on would put the whole directory's identity data
 * into somebody's scrollback and their CI output.
 */
class RebuildIdentityFingerprintsCommand extends Command
{
    protected $signature = 'parties:rebuild-identity-fingerprints
                            {--chunk=200 : How many records to load at a time}';

    protected $description = 'Recompute keyed identity fingerprints for individuals and companies';

    public function handle(IdentityFingerprint $fingerprint): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        $individuals = ['scanned' => 0, 'updated' => 0, 'cleared' => 0];
        $companies = ['scanned' => 0, 'updated' => 0, 'cleared' => 0];

        // `withTrashed`-free by design: the subtypes carry no soft delete, and an
        // archived Party's subtype row is still present, so this reaches history
        // as well as live records. A fingerprint on an archived record costs
        // nothing — duplicate *detection* filters archived roots out separately.
        Individual::query()->chunkById($chunk, function ($batch) use ($fingerprint, &$individuals): void {
            foreach ($batch as $individual) {
                $individuals['scanned']++;

                $nik = $fingerprint->of($individual->nik);
                $npwp = $fingerprint->of($individual->npwp);

                if ($nik === $individual->nik_fingerprint && $npwp === $individual->npwp_fingerprint) {
                    continue;
                }

                if ($nik === null && $npwp === null) {
                    $individuals['cleared']++;
                } else {
                    $individuals['updated']++;
                }

                // `saveQuietly`-equivalent intent: only the fingerprint columns
                // move. The encrypted values are read and never rewritten, so no
                // identifier is re-encrypted just to rebuild a derived column.
                $individual->forceFill([
                    'nik_fingerprint' => $nik,
                    'npwp_fingerprint' => $npwp,
                ])->save();
            }
        }, 'party_id');

        Company::query()->chunkById($chunk, function ($batch) use ($fingerprint, &$companies): void {
            foreach ($batch as $company) {
                $companies['scanned']++;

                $taxId = $fingerprint->of($company->tax_id);

                if ($taxId === $company->tax_id_fingerprint) {
                    continue;
                }

                if ($taxId === null) {
                    $companies['cleared']++;
                } else {
                    $companies['updated']++;
                }

                $company->forceFill(['tax_id_fingerprint' => $taxId])->save();
            }
        }, 'party_id');

        $this->table(
            ['Subject', 'Scanned', 'Fingerprinted', 'Cleared'],
            [
                ['Individuals', $individuals['scanned'], $individuals['updated'], $individuals['cleared']],
                ['Companies', $companies['scanned'], $companies['updated'], $companies['cleared']],
            ],
        );

        $this->info('Identity fingerprints rebuilt.');

        return self::SUCCESS;
    }
}
