"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { toProjectErrorKey } from "@/features/projects/project-errors";
import { Link } from "@/i18n/navigation";
import {
  addProjectParty,
  getProjectParties,
  getProjectPartyCandidates,
  projectPartyQueryKeys,
  removeProjectParty,
  updateProjectParty,
} from "@/services/project-parties";
import type { ProjectParty } from "@/types/project-party";

/**
 * Who takes part in this Project.
 *
 * **Current involvement, not a history.** The list shows the participations that
 * exist right now; removing one deletes it, and nothing records that it was ever
 * there (D-098). The confirmation wording says so rather than implying an undo
 * the product does not have.
 *
 * **No legal role dropdown.** `role_code` is a free optional text field because
 * no canonical participant-role vocabulary exists — offering a list of
 * CLIENT / BUYER / SELLER would present an invented catalogue as though it were
 * established (D-092, CLAUDE.md section 62).
 *
 * **A Party is linked only when `can_view_party` says the link would work.** That
 * flag comes from real Party visibility, per subtype, so a name the reader cannot
 * open renders as plain text instead of a link that answers 403. The row itself
 * always renders: a participation the office recorded is Project data, and hiding
 * it would misreport who is involved.
 */
export function ProjectPartiesSection({ projectId }: { projectId: string }) {
  const t = useTranslations("projectParties");
  const tActions = useTranslations("actions");

  const query = useQuery({
    queryKey: projectPartyQueryKeys.all(projectId),
    queryFn: () => getProjectParties(projectId),
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        {[0, 1].map((row) => (
          <Skeleton key={row} className="h-12 w-full" />
        ))}
      </div>
    );
  }

  if (query.isError) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toProjectErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  const participants = query.data?.data ?? [];
  const canManage = query.data?.meta.can_manage ?? false;

  return (
    <div className="flex flex-col gap-4">
      {canManage ? <AddParticipant projectId={projectId} /> : null}

      {participants.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("empty")}</p>
      ) : (
        <ul className="divide-border divide-y">
          {participants.map((participant) => (
            <ParticipantRow key={participant.id} projectId={projectId} participant={participant} />
          ))}
        </ul>
      )}
    </div>
  );
}

function Chip({ children, emphasis = false }: { children: string; emphasis?: boolean }) {
  return <Badge tone={emphasis ? "primary" : "muted"}>{children}</Badge>;
}

function ParticipantRow({
  projectId,
  participant,
}: {
  projectId: string;
  participant: ProjectParty;
}) {
  const t = useTranslations("projectParties");
  const party = participant.party;

  return (
    <li className="flex flex-wrap items-start justify-between gap-3 py-3">
      <div className="flex min-w-0 flex-col gap-1">
        <div className="flex flex-wrap items-center gap-2">
          {party === null ? (
            <span className="text-muted-foreground text-sm">{t("unknownParty")}</span>
          ) : party.can_view_party ? (
            <Link
              href={
                party.party_type === "COMPANY"
                  ? `/parties/companies/${party.id}`
                  : `/parties/individuals/${party.id}`
              }
              className="font-medium underline-offset-4 hover:underline"
            >
              {party.display_name}
            </Link>
          ) : (
            // Not a link: the Party surfaces would refuse this reader, and an
            // anchor that answers 403 is worse than plain text.
            <span className="font-medium">{party.display_name}</span>
          )}

          {/* Plain bordered chips, matching ProjectStatusBadge: the text is the
              information and the border is only a boundary, so nothing here
              relies on colour alone (CLAUDE.md section 49). */}
          {party ? <Chip>{t(`partyTypes.${party.party_type}`)}</Chip> : null}

          {participant.is_primary ? <Chip emphasis>{t("primary")}</Chip> : null}

          {party?.is_archived ? <Chip>{t("archivedParty")}</Chip> : null}
        </div>

        <p className="text-muted-foreground text-sm">
          {participant.role_code ?? t("noRole")}
          {participant.notes ? ` — ${participant.notes}` : ""}
        </p>
      </div>

      {participant.can_manage ? (
        <div className="flex gap-2">
          <EditParticipant projectId={projectId} participant={participant} />
          <RemoveParticipant projectId={projectId} participant={participant} />
        </div>
      ) : null}
    </li>
  );
}

function AddParticipant({ projectId }: { projectId: string }) {
  const t = useTranslations("projectParties");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [partyId, setPartyId] = useState("");
  const [roleCode, setRoleCode] = useState("");
  const [isPrimary, setIsPrimary] = useState(false);
  const [notes, setNotes] = useState("");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => setSearch(searchInput.trim()), 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const candidates = useQuery({
    queryKey: projectPartyQueryKeys.candidates(projectId, search),
    queryFn: () => getProjectPartyCandidates(projectId, search),
    enabled: open,
    retry: false,
  });

  const mutation = useMutation({
    mutationFn: () =>
      addProjectParty(projectId, {
        party_id: partyId,
        role_code: roleCode === "" ? null : roleCode,
        is_primary: isPrimary,
        notes: notes === "" ? null : notes,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: projectPartyQueryKeys.all(projectId) });
      setOpen(false);
      setPartyId("");
      setRoleCode("");
      setIsPrimary(false);
      setNotes("");
      setErrorKey(null);
    },
    onError: (error: unknown) => setErrorKey(toProjectErrorKey(error)),
  });

  const options = candidates.data?.parties ?? [];

  return (
    <>
      <div>
        <Button size="sm" className="gap-2" onClick={() => setOpen(true)}>
          <Plus aria-hidden="true" />
          {t("addAction")}
        </Button>
      </div>

      <Dialog open={open} onOpenChange={(next) => setOpen(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("addTitle")}</DialogTitle>
            <DialogDescription>{t("addDescription")}</DialogDescription>
          </DialogHeader>

          {errorKey ? (
            <p
              role="alert"
              className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
            >
              {t(`errors.${errorKey}`)}
            </p>
          ) : null}

          <form
            noValidate
            className="flex flex-col gap-4"
            onSubmit={(event) => {
              event.preventDefault();
              setErrorKey(null);
              mutation.mutate();
            }}
          >
            <div className="flex flex-col gap-2">
              <Label htmlFor="participant-search">{t("searchLabel")}</Label>
              <Input
                id="participant-search"
                value={searchInput}
                placeholder={t("searchPlaceholder")}
                onChange={(event) => setSearchInput(event.target.value)}
              />
            </div>

            <div className="flex flex-col gap-2">
              <Label htmlFor="participant-party">{t("partyLabel")}</Label>
              <Select
                id="participant-party"
                value={partyId}
                onChange={(event) => setPartyId(event.target.value)}
              >
                <option value="">{t("selectParty")}</option>
                {options.map((candidate) => (
                  <option key={candidate.id} value={candidate.id}>
                    {candidate.display_name}
                  </option>
                ))}
              </Select>

              {!candidates.isPending && options.length === 0 ? (
                // Empty is a legitimate outcome, not an error: managing
                // participation is not by itself permission to see Parties.
                <p className="text-muted-foreground text-xs">{t("noCandidates")}</p>
              ) : (
                <p className="text-muted-foreground text-xs">{t("candidateHint")}</p>
              )}
            </div>

            <RelationshipFields
              roleCode={roleCode}
              isPrimary={isPrimary}
              notes={notes}
              onRoleCode={setRoleCode}
              onIsPrimary={setIsPrimary}
              onNotes={setNotes}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                {tActions("cancel")}
              </Button>
              <Button type="submit" disabled={partyId === "" || mutation.isPending}>
                {mutation.isPending ? tActions("saving") : t("addConfirm")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
}

function EditParticipant({
  projectId,
  participant,
}: {
  projectId: string;
  participant: ProjectParty;
}) {
  const t = useTranslations("projectParties");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [roleCode, setRoleCode] = useState(participant.role_code ?? "");
  const [isPrimary, setIsPrimary] = useState(participant.is_primary);
  const [notes, setNotes] = useState(participant.notes ?? "");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () =>
      updateProjectParty(projectId, participant.id, {
        role_code: roleCode === "" ? null : roleCode,
        is_primary: isPrimary,
        notes: notes === "" ? null : notes,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: projectPartyQueryKeys.all(projectId) });
      setOpen(false);
      setErrorKey(null);
    },
    onError: (error: unknown) => setErrorKey(toProjectErrorKey(error)),
  });

  return (
    <>
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        {t("editAction")}
      </Button>

      <Dialog open={open} onOpenChange={(next) => setOpen(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("editTitle")}</DialogTitle>
            {/* The Party itself is not editable here: swapping it would be a
                different relationship, so the interface removes and adds. */}
            <DialogDescription>{t("editDescription")}</DialogDescription>
          </DialogHeader>

          {errorKey ? (
            <p
              role="alert"
              className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
            >
              {t(`errors.${errorKey}`)}
            </p>
          ) : null}

          <form
            noValidate
            className="flex flex-col gap-4"
            onSubmit={(event) => {
              event.preventDefault();
              setErrorKey(null);
              mutation.mutate();
            }}
          >
            <RelationshipFields
              roleCode={roleCode}
              isPrimary={isPrimary}
              notes={notes}
              onRoleCode={setRoleCode}
              onIsPrimary={setIsPrimary}
              onNotes={setNotes}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                {tActions("cancel")}
              </Button>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending ? tActions("saving") : tActions("save")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
}

/**
 * The three correctable fields, shared by add and edit so the two cannot drift.
 *
 * `role_code` is a text input rather than a select, deliberately — see the
 * section docblock.
 */
function RelationshipFields({
  roleCode,
  isPrimary,
  notes,
  onRoleCode,
  onIsPrimary,
  onNotes,
}: {
  roleCode: string;
  isPrimary: boolean;
  notes: string;
  onRoleCode: (value: string) => void;
  onIsPrimary: (value: boolean) => void;
  onNotes: (value: string) => void;
}) {
  const t = useTranslations("projectParties");

  return (
    <>
      <div className="flex flex-col gap-2">
        <Label htmlFor="participant-role">{t("roleLabel")}</Label>
        <Input
          id="participant-role"
          value={roleCode}
          maxLength={30}
          placeholder={t("rolePlaceholder")}
          onChange={(event) => onRoleCode(event.target.value)}
        />
        <p className="text-muted-foreground text-xs">{t("roleHint")}</p>
      </div>

      <div className="flex items-start gap-2">
        <Checkbox
          id="participant-primary"
          className="mt-1"
          checked={isPrimary}
          onCheckedChange={(checked) => onIsPrimary(checked === true)}
        />
        <div className="flex flex-col gap-1">
          <Label htmlFor="participant-primary">{t("primaryLabel")}</Label>
          <p className="text-muted-foreground text-xs">{t("primaryHint")}</p>
        </div>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="participant-notes">{t("notesLabel")}</Label>
        <Input
          id="participant-notes"
          value={notes}
          maxLength={2000}
          onChange={(event) => onNotes(event.target.value)}
        />
      </div>
    </>
  );
}

function RemoveParticipant({
  projectId,
  participant,
}: {
  projectId: string;
  participant: ProjectParty;
}) {
  const t = useTranslations("projectParties");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => removeProjectParty(projectId, participant.id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: projectPartyQueryKeys.all(projectId) });
      setOpen(false);
      setErrorKey(null);
    },
    onError: (error: unknown) => setErrorKey(toProjectErrorKey(error)),
  });

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        aria-label={t("removeAction")}
        onClick={() => setOpen(true)}
      >
        <Trash2 aria-hidden="true" />
      </Button>

      <Dialog open={open} onOpenChange={(next) => setOpen(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("removeTitle")}</DialogTitle>
            {/* States plainly that the link is deleted and that neither record
                is affected — and that there is no history to restore from. */}
            <DialogDescription>{t("removeDescription")}</DialogDescription>
          </DialogHeader>

          {errorKey ? (
            <p
              role="alert"
              className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
            >
              {t(`errors.${errorKey}`)}
            </p>
          ) : null}

          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              {tActions("cancel")}
            </Button>
            <Button disabled={mutation.isPending} onClick={() => mutation.mutate()}>
              {mutation.isPending ? tActions("saving") : t("removeConfirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
