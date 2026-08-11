"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import { IdentitySection } from "@/features/individuals/identity-section";
import { toIndividualErrorKey } from "@/features/individuals/individual-errors";
import { useCurrentUser } from "@/features/auth/use-current-user";
import { Link, useRouter } from "@/i18n/navigation";
import { can } from "@/lib/permissions/can";
import { archiveIndividual, getIndividual, individualQueryKeys } from "@/services/individuals";

/**
 * One Individual.
 *
 * Two sections, and only two: **Profile** and **Identity**. There is deliberately
 * no Companies, Documents, Projects, Matters, or Timeline tab — those modules do
 * not exist, and an empty tab is a promise the product cannot keep (D-064).
 * Company relationships arrive with M2.4 and get their section then.
 *
 * The Identity section is a separate component with its own query, so a caller
 * who may view the record but not its identity simply does not load it — the
 * profile still renders, and the missing section is a normal condition rather
 * than an error.
 */
export function IndividualDetail({ individualId }: { individualId: string }) {
  const t = useTranslations("individuals");
  const tActions = useTranslations("actions");

  const { data: user } = useCurrentUser();

  const query = useQuery({
    queryKey: individualQueryKeys.detail(individualId),
    queryFn: () => getIndividual(individualId),
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-3" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        {[0, 1].map((row) => (
          <Skeleton key={row} className="h-40 w-full" />
        ))}
      </div>
    );
  }

  if (query.isError || !query.data) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toIndividualErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  const individual = query.data;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold tracking-tight">{individual.full_name}</h1>
          <p className="text-muted-foreground text-sm">
            {individual.office ? `${individual.office.code} — ${individual.office.name}` : "—"}
          </p>
        </div>

        <div className="flex gap-2">
          <PermissionGuard permission="parties.update">
            <Button
              variant="outline"
              render={<Link href={`/parties/individuals/${individual.id}/edit`} />}
            >
              {t("editAction")}
            </Button>
          </PermissionGuard>

          <PermissionGuard permission="parties.archive">
            <ArchiveButton individualId={individual.id} />
          </PermissionGuard>
        </div>
      </div>

      <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
        <div className="flex flex-col gap-1">
          <h2 className="text-base font-medium">{t("profileSection")}</h2>
          <p className="text-muted-foreground text-sm">{t("profileDescription")}</p>
        </div>

        <dl className="grid gap-4 sm:grid-cols-2">
          <Detail label={t("phoneLabel")} value={individual.primary_phone} />
          <Detail label={t("emailLabel")} value={individual.primary_email} />
          <Detail label={t("birthPlaceLabel")} value={individual.birth_place} />
          <Detail label={t("birthDateLabel")} value={individual.birth_date} />
          <Detail label={t("occupationLabel")} value={individual.occupation} />
          <Detail label={t("nationalityLabel")} value={individual.nationality} />
          <Detail label={t("addressLabel")} value={individual.address} />
          <Detail label={t("cityLabel")} value={individual.city} />
          <Detail label={t("provinceLabel")} value={individual.province} />
          <Detail label={t("postalCodeLabel")} value={individual.postal_code} />
        </dl>
      </section>

      <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
        <h2 className="text-base font-medium">{t("identitySection")}</h2>

        <IdentitySection
          individualId={individual.id}
          canUpdate={can(user, "parties.identity.update")}
        />
      </section>
    </div>
  );
}

function Detail({ label, value }: { label: string; value: string | null }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-sm font-medium">{label}</dt>
      <dd className="text-muted-foreground text-sm">{value ?? "—"}</dd>
    </div>
  );
}

/**
 * Archive, behind a confirmation.
 *
 * The wording describes the operational consequence and says the record is
 * retained rather than deleted. Claiming a legal deletion would be false: other
 * records may refer to this one, and Party-domain data is master data (D-081).
 */
function ArchiveButton({ individualId }: { individualId: string }) {
  const t = useTranslations("individuals");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => archiveIndividual(individualId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: individualQueryKeys.all });
      setOpen(false);
      router.push("/parties/individuals");
    },
    onError: (error: unknown) => setErrorKey(toIndividualErrorKey(error)),
  });

  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        {t("archiveAction")}
      </Button>

      <Dialog open={open} onOpenChange={(next) => (next ? undefined : setOpen(false))}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("archiveTitle")}</DialogTitle>
            <DialogDescription>{t("archiveDescription")}</DialogDescription>
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
              {mutation.isPending ? tActions("saving") : t("archiveConfirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
