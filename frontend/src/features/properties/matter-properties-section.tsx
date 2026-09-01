"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { EmptyState } from "@/components/feedback/empty-state";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { PropertyRoleBadge, PropertyTypeBadge } from "@/features/properties/property-badges";
import { hasFieldError, toPropertyErrorKey } from "@/features/properties/property-errors";
import { Link } from "@/i18n/navigation";
import {
  attachMatterProperty,
  detachMatterProperty,
  getMatterProperties,
  getProperties,
  getPropertyOptions,
  propertyKeys,
} from "@/services/properties";

/**
 * Which land a PPAT Matter concerns (M7.3, D-121).
 *
 * **A section, not a tab**, following the ones already on the Matter page and the ruling
 * every milestone since M5.2 has made: the repository has no `Tabs` primitive.
 *
 * **It asks its own endpoint**, and that is the point rather than an implementation
 * detail. The list answers to `properties.view` with its own Data Scope — reaching a
 * Matter confers no Property authority — while attaching and detaching answer to
 * `ppat.matters.update`, because the junction row is *Matter composition*: it says which
 * parcel this piece of work is about, the way participation says which people it is
 * about.
 *
 * **No capability names this act**, so each side is judged by one that already exists.
 * The picker draws only from Properties the caller can already reach, and the backend
 * resolves the choice through the same visibility again — composing a Matter never
 * becomes a way to discover which Properties exist.
 *
 * **Rendered only on PPAT Matters.** `CLAUDE.md` section 16 lists Property among the
 * PPAT-specific concepts, and there is no Notary counterpart route at all.
 *
 * **Detaching removes the junction row and nothing else** — never the Matter, never the
 * Property, never a link in a chain of title. `matter_properties` has no `deleted_at`,
 * so the confirmation says the assertion is withdrawn rather than implying an undo.
 */
export function MatterPropertiesSection({ matterId }: { matterId: string }) {
  const t = useTranslations("properties");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [error, setError] = useState<string | null>(null);

  const query = useQuery({
    queryKey: propertyKeys.matterProperties(matterId),
    queryFn: () => getMatterProperties(matterId),
  });

  const detach = useMutation({
    mutationFn: (propertyId: string) => detachMatterProperty(matterId, propertyId),
    onSuccess: async () => {
      setError(null);
      await queryClient.invalidateQueries({ queryKey: propertyKeys.matterProperties(matterId) });
    },
    onError: (failure: unknown) => setError(t(`errors.${toPropertyErrorKey(failure)}`)),
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
        title={t("matterSectionErrorTitle")}
        description={t(`errors.${toPropertyErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  const properties = query.data?.data ?? [];
  const canManage = query.data?.meta.can_manage ?? false;

  return (
    <>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h3 className="text-sm font-medium">{t("matterSectionTitle")}</h3>
          <p className="text-muted-foreground text-xs">{t("matterSectionHint")}</p>
        </div>
      </div>

      {error ? (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      ) : null}

      {canManage ? <AttachPropertyForm matterId={matterId} /> : null}

      {properties.length === 0 ? (
        <EmptyState
          title={t("matterSectionEmptyTitle")}
          description={t("matterSectionEmptyDescription")}
        />
      ) : (
        <ul className="flex flex-col gap-2">
          {properties.map((property) => (
            <li
              key={property.id}
              className="border-border flex flex-wrap items-center justify-between gap-3 rounded-md border px-3 py-2"
            >
              <div className="flex flex-col gap-1">
                <Link
                  href={`/ppat/properties/${property.id}`}
                  className="text-sm font-medium underline-offset-4 hover:underline"
                >
                  {property.certificate_number}
                </Link>
                <span className="text-muted-foreground text-xs">{property.address}</span>
              </div>

              <div className="flex items-center gap-2">
                <PropertyTypeBadge type={property.property_type} />
                <PropertyRoleBadge code={property.role_code} />

                {canManage ? (
                  <Button
                    variant="ghost"
                    size="sm"
                    aria-label={t("detach")}
                    disabled={detach.isPending}
                    onClick={() => {
                      setError(null);

                      if (window.confirm(t("detachConfirm"))) {
                        detach.mutate(property.id);
                      }
                    }}
                  >
                    <Trash2 aria-hidden="true" className="size-4" />
                  </Button>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

/**
 * Name another parcel this Matter concerns.
 *
 * **The picker searches the Property list**, which is scoped by `properties.view` on
 * its own endpoint — so it can never offer a parcel the caller could not already open.
 * Archived parcels are excluded: a retired record should not be picked for new work,
 * the rule participation applies to archived Parties.
 *
 * **`role_code` is free text with a `datalist`.** The ERD calls `TRANSACTION_OBJECT`,
 * `COLLATERAL` and `RELATED_PROPERTY` *"Example role codes"*, so a `<select>` would
 * present three values as the vocabulary when the ERD declined to.
 */
function AttachPropertyForm({ matterId }: { matterId: string }) {
  const t = useTranslations("properties");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [propertyId, setPropertyId] = useState("");
  const [roleCode, setRoleCode] = useState("");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => setSearch(searchInput.trim()), 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const candidateQuery = { page: 1, per_page: 20, search, archived: "" as const };

  const candidates = useQuery({
    queryKey: propertyKeys.list(candidateQuery),
    queryFn: () => getProperties(candidateQuery),
    enabled: open,
  });

  const options = useQuery({
    queryKey: propertyKeys.options(),
    queryFn: getPropertyOptions,
    enabled: open,
  });

  const mutation = useMutation({
    mutationFn: () =>
      attachMatterProperty(matterId, {
        property_id: propertyId,
        role_code: roleCode.trim() === "" ? null : roleCode.trim(),
      }),
    onSuccess: async () => {
      setError(null);
      setOpen(false);
      setPropertyId("");
      setRoleCode("");
      setSearchInput("");

      await queryClient.invalidateQueries({ queryKey: propertyKeys.matterProperties(matterId) });
    },
    onError: (failure: unknown) => {
      setError(
        hasFieldError(failure, "property_id")
          ? t("validation.propertyUnavailable")
          : t(`errors.${toPropertyErrorKey(failure)}`),
      );
    },
  });

  if (!open) {
    return (
      <div>
        <Button variant="outline" size="sm" className="gap-2" onClick={() => setOpen(true)}>
          <Plus aria-hidden="true" />
          {t("attach")}
        </Button>
      </div>
    );
  }

  return (
    <form
      className="border-border flex flex-col gap-4 rounded-lg border p-4"
      onSubmit={(event) => {
        event.preventDefault();
        setError(null);

        if (propertyId !== "") {
          mutation.mutate();
        }
      }}
    >
      <h4 className="text-sm font-medium">{t("attach")}</h4>

      {error ? (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      ) : null}

      <div className="flex flex-col gap-2">
        <Label htmlFor="attach-search">{t("findProperty")}</Label>
        <Input
          id="attach-search"
          placeholder={t("findPropertyPlaceholder")}
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
        />
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="attach-property">{t("title")}</Label>
        <select
          id="attach-property"
          className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
          value={propertyId}
          onChange={(event) => setPropertyId(event.target.value)}
        >
          <option value="">{t("selectProperty")}</option>
          {(candidates.data?.data ?? []).map((property) => (
            <option key={property.id} value={property.id}>
              {property.certificate_number} — {property.address}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="attach-role">{t("roleCode")}</Label>
        <Input
          id="attach-role"
          list="matter-role-examples"
          value={roleCode}
          onChange={(event) => setRoleCode(event.target.value)}
        />
        <datalist id="matter-role-examples">
          {(options.data?.matter_role_examples ?? []).map((code) => (
            <option key={code} value={code} />
          ))}
        </datalist>
        <p className="text-muted-foreground text-xs">{t("roleCodeHint")}</p>
      </div>

      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={propertyId === "" || mutation.isPending}>
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
        <Button type="button" variant="outline" size="sm" onClick={() => setOpen(false)}>
          {tActions("cancel")}
        </Button>
      </div>
    </form>
  );
}
