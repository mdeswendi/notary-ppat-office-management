"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { Info } from "lucide-react";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { partyDetailHref } from "@/features/parties/party-links";
import {
  toDuplicateCheckErrorKey,
  type DuplicateCheckErrorKey,
} from "@/features/parties/party-errors";
import { Link } from "@/i18n/navigation";
import { KNOWN_DUPLICATE_SIGNALS, type DuplicateCandidate } from "@/types/party-duplicate";

/**
 * Duplicate assistance, as the office meets it (D-084).
 *
 * **Advisory, and the interface must not quietly promote it.** A candidate is
 * evidence a person may want to look at, not a finding, not an error, and not a
 * claim that two records are the same person or organization — that assertion is
 * a human judgement the software has no standing to make. So this renders as a
 * neutral panel rather than a destructive alert, announces itself politely
 * rather than assertively, and always offers a way forward.
 *
 * **It cannot become a blocker.** The panel appears once for a given save
 * attempt; acknowledging it lets every subsequent save through, and a check that
 * fails outright lets the save through immediately. There is no state in which a
 * candidate leaves the Save control permanently disabled.
 *
 * **There is no Merge, Replace, Use existing, or Archive duplicate.** Combining
 * two Parties would rewrite legal master data across records that already refer
 * to them, under rules no canonical document defines. M2 surfaces the evidence
 * and stops there.
 */

/**
 * The result of a check, held for exactly as long as the decision takes.
 *
 * Candidates live in this hook's state and nowhere else — no query cache, no
 * `localStorage`, no `sessionStorage`, no URL — and are discarded on
 * acknowledgement, dismissal, reset, and unmount. That matters most on the
 * identity surfaces, where the request that produced them carried a NIK, an
 * NPWP, or a tax identifier.
 */
export type DuplicateAdvisory = {
  candidates: DuplicateCandidate[];
  /** Why assistance could not run. Never why it found something. */
  unavailable: DuplicateCheckErrorKey | null;
  checking: boolean;
  /**
   * Run `check`, unless the caller has already acknowledged a warning or there
   * is nothing to compare (`null`). Resolves **true** when the save may proceed.
   */
  gate: (check: (() => Promise<DuplicateCandidate[]>) | null) => Promise<boolean>;
  /** "Continue anyway" — the person has looked and decided. */
  acknowledge: () => void;
  /** "Review" — close the panel and go back to the form, saving nothing. */
  dismiss: () => void;
  /** Forget everything, including the acknowledgement. */
  reset: () => void;
};

export function useDuplicateAdvisory(): DuplicateAdvisory {
  const [candidates, setCandidates] = useState<DuplicateCandidate[]>([]);
  const [unavailable, setUnavailable] = useState<DuplicateCheckErrorKey | null>(null);
  const [checking, setChecking] = useState(false);

  /**
   * A ref rather than state: `gate` reads it during a submit handler that was
   * created before `acknowledge` ran, and a stale closure here would re-show the
   * panel the person just dismissed — turning the advisory into the blocker it
   * must never be.
   */
  const acknowledged = useRef(false);

  // Discard on unmount. Leaving the form must genuinely drop the result.
  useEffect(() => () => setCandidates([]), []);

  const gate = useCallback(async (check: (() => Promise<DuplicateCandidate[]>) | null) => {
    if (acknowledged.current || check === null) {
      return true;
    }

    setChecking(true);
    setUnavailable(null);

    try {
      const found = await check();

      if (found.length === 0) {
        return true;
      }

      setCandidates(found);

      return false;
    } catch (error: unknown) {
      // Assistance failing must never stop a legitimate save. The notice says
      // the check did not run — never that a duplicate exists, because
      // inferring one from a refusal would rebuild the existence oracle the
      // field permission exists to prevent.
      setUnavailable(toDuplicateCheckErrorKey(error));

      return true;
    } finally {
      setChecking(false);
    }
  }, []);

  const acknowledge = useCallback(() => {
    acknowledged.current = true;
    setCandidates([]);
  }, []);

  const dismiss = useCallback(() => setCandidates([]), []);

  const reset = useCallback(() => {
    acknowledged.current = false;
    setCandidates([]);
    setUnavailable(null);
  }, []);

  return { candidates, unavailable, checking, gate, acknowledge, dismiss, reset };
}

/**
 * The panel itself.
 *
 * Each candidate shows a name, its type, its Office, and which exact tests
 * matched. **No identifier value appears** — not the NIK that matched, not a
 * mask of it, and nothing derived from it. Knowing *that* a NIK matched is the
 * disclosure the sensitive permission authorizes; the value belongs to the
 * reviewed reveal surface (D-082).
 *
 * Candidate links open in a new tab so reviewing one does not discard the form
 * the person is in the middle of filling in. Every candidate returned is already
 * one this caller may reach — the backend scopes the comparison to records they
 * can see — so the link is never an offer the API would then refuse.
 */
export function DuplicateAdvisoryPanel({
  candidates,
  onReview,
  onContinue,
  continueDisabled = false,
}: {
  candidates: DuplicateCandidate[];
  onReview: () => void;
  onContinue: () => void;
  continueDisabled?: boolean;
}) {
  const t = useTranslations("partyDuplicates");

  if (candidates.length === 0) {
    return null;
  }

  return (
    // `status`, not `alert`: this is information to weigh, not a failure.
    <section
      role="status"
      aria-live="polite"
      className="border-border bg-muted/40 flex flex-col gap-3 rounded-md border p-4"
    >
      <div className="flex items-start gap-2">
        <Info aria-hidden="true" className="text-muted-foreground mt-0.5 size-4 shrink-0" />
        <div className="flex flex-col gap-1">
          <h3 className="text-sm font-medium">{t("title")}</h3>
          <p className="text-muted-foreground text-sm">{t("description")}</p>
        </div>
      </div>

      <ul className="flex flex-col gap-2">
        {candidates.map((candidate) => (
          <CandidateRow key={candidate.id} candidate={candidate} />
        ))}
      </ul>

      <div className="flex flex-wrap gap-2">
        <Button type="button" variant="outline" size="sm" onClick={onReview}>
          {t("review")}
        </Button>
        <Button type="button" size="sm" disabled={continueDisabled} onClick={onContinue}>
          {t("continueAnyway")}
        </Button>
      </div>
    </section>
  );
}

function CandidateRow({ candidate }: { candidate: DuplicateCandidate }) {
  const t = useTranslations("partyDuplicates");

  const href = partyDetailHref(candidate.party_type, candidate.id);
  const name = candidate.display_name ?? t("unnamed");

  return (
    <li className="border-border bg-background flex flex-col gap-1 rounded-md border p-3">
      <div className="flex flex-wrap items-center gap-2">
        {href === null ? (
          <span className="text-sm font-medium">{name}</span>
        ) : (
          <Link
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="text-sm font-medium underline-offset-4 hover:underline"
          >
            {name}
          </Link>
        )}

        <span className="border-border text-muted-foreground rounded-full border px-2 py-0.5 text-xs">
          {t(`partyTypes.${candidate.party_type}`)}
        </span>

        {candidate.office ? (
          <span className="text-muted-foreground text-xs">
            {candidate.office.code} — {candidate.office.name}
          </span>
        ) : null}
      </div>

      <p className="text-muted-foreground text-xs">
        {t("matchedSignals")}: <SignalLabels signals={candidate.signals} />
      </p>
    </li>
  );
}

/**
 * Signal codes, translated for reading.
 *
 * Stable codes travel over the API; only the label is translated (CLAUDE.md
 * section 12). An unrecognized code falls back to a generic label rather than
 * throwing — a backend that gains a signal must not blank the panel — and is
 * never printed raw, because a code is not a sentence.
 */
function SignalLabels({ signals }: { signals: string[] }) {
  const t = useTranslations("partyDuplicates");

  const labels = signals.map((signal) =>
    KNOWN_DUPLICATE_SIGNALS.has(signal) ? t(`signals.${signal}`) : t("signals.OTHER"),
  );

  return <span>{labels.join(" · ")}</span>;
}

/**
 * The notice shown when assistance could not run.
 *
 * Says only that the check did not happen. It must never suggest a duplicate was
 * found, hidden, or suppressed: a `403` means this caller may not be told about
 * matches on that identifier, and reading existence into a refusal is precisely
 * the inference the permission exists to block.
 */
export function DuplicateCheckNotice({ reason }: { reason: DuplicateCheckErrorKey | null }) {
  const t = useTranslations("partyDuplicates");

  if (reason === null) {
    return null;
  }

  return (
    <p role="status" className="text-muted-foreground text-sm">
      {t(`unavailable.${reason}`)}
    </p>
  );
}
