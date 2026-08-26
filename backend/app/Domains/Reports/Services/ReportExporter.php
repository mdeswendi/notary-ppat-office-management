<?php

namespace App\Domains\Reports\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turn a scoped report query into a CSV file (M8.3, D-126).
 *
 * ## It streams, and it re-uses the query it was given
 *
 * The **already-scoped** builder is chunked, so an export of forty thousand rows
 * never holds forty thousand models in memory. Taking the built query rather than
 * rebuilding one is also what makes the lock's rule enforceable: *"export
 * produces the same rows the actor just saw — never more, never an unscoped
 * variant"*. There is no second code path here that could forget a predicate.
 *
 * ## Masking travels with the rows
 *
 * The row mapper is supplied by the caller, which is the same mapper the JSON
 * surface uses. A financial export by an actor without `billing.amount.view`
 * therefore carries no amounts — not blanked columns, **absent ones** — because
 * the header is built from the same decision (D-125, and the lock's section 10).
 *
 * ## CSV, deliberately, and not a spreadsheet
 *
 * No PDF and no XLSX. `CLAUDE.md` section 62's discipline applies further than it
 * first looks: a formatted document with a letterhead starts to resemble
 * something an office might file, and **a report that looks like a statutory
 * return is worse than no report**. CSV is unambiguously working data.
 *
 * A UTF-8 BOM is written because Excel on Windows otherwise reads `Sertifikat`
 * as mojibake, and this office runs on Windows.
 */
class ReportExporter
{
    /**
     * How many rows are pulled from the database at a time.
     */
    private const CHUNK = 500;

    /**
     * Stream a scoped query as CSV.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  already narrowed by visibility and filters
     * @param  array<int, string>  $headers  column headings, in order
     * @param  callable(TModel): array<int, mixed>  $row  one model to one CSV line
     */
    public function stream(Builder $query, string $name, array $headers, callable $row): StreamedResponse
    {
        $filename = sprintf('%s-%s.csv', $name, Date::now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($query, $headers, $row): void {
            $handle = fopen('php://output', 'wb');

            // Excel on Windows needs this to read UTF-8; without it every
            // Indonesian name with a diacritic arrives mangled.
            fwrite($handle, "\u{FEFF}");

            fputcsv($handle, $headers);

            // `chunkById` rather than `chunk`: a plain offset/limit walk can skip
            // or repeat rows when the underlying set changes mid-export, and an
            // export that silently drops a row is worse than one that fails.
            $query->chunkById(self::CHUNK, function ($models) use ($handle, $row): void {
                foreach ($models as $model) {
                    fputcsv($handle, array_map(
                        static fn (mixed $value): string => $value === null ? '' : (string) $value,
                        $row($model),
                    ));
                }

                flush();
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // The file is derived from records the caller may read; it must not
            // sit in a shared cache afterwards.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
