<?php

namespace App\Domains\Document\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Document\Enums\DocumentStatus;
use App\Domains\Document\Exceptions\DocumentStatusNotEligible;
use App\Models\Document;
use App\Models\User;

/**
 * Accept a Document as what it claims to be (M5.2, D-117).
 *
 * `RECEIVED` or `UNDER_REVIEW` → `VERIFIED`. Anything else is 422 — verifying a
 * document twice, or verifying one that has been archived, is not an act the
 * office can perform.
 *
 * **No `verified_at` or `verified_by` is written, and that is deliberate.**
 * `03_DATABASE_ERD.md` section 13 gives `documents` neither column; the pair
 * belongs to `matter_requirements` and `warkah`, which are different tables with
 * their own milestones. Adding them here would extend the canonical field list on
 * this milestone's own authority.
 *
 * Who verified and when is exactly what the audit store records, and D-115
 * already rules audit is required, absent, and not to be improvised. Writing it in
 * two places would guarantee the two eventually disagree — so the status is the
 * fact, `updated_by` names the last person to touch the record, and the event
 * itself waits for the store built to hold it.
 */
class VerifyDocument
{
    public function __construct(private readonly EventRecorder $events) {}

    public function handle(User $actor, Document $document): Document
    {
        if (! $document->status->isVerifiable()) {
            throw new DocumentStatusNotEligible($document->status, 'verified');
        }

        $from = $document->status->value;

        $document->status = DocumentStatus::VERIFIED;
        $document->updated_by = $actor->getKey();
        $document->save();

        $this->events->statusChanged(
            $document,
            $actor,
            $from,
            $document->status->value,
            ActivityType::DOCUMENT_VERIFIED,
            ['title' => $document->title],
        );

        return $document;
    }
}
