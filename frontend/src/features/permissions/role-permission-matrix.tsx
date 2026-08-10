"use client";

import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Search, TriangleAlert } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { toPermissionErrorKey } from "@/features/permissions/permission-errors";
import { Link } from "@/i18n/navigation";
import { cn } from "@/lib/utils";
import {
  getPermissionCatalogue,
  getRolePermissions,
  permissionQueryKeys,
  saveRolePermissions,
} from "@/services/permissions";
import { roleQueryKeys } from "@/services/roles";

/** code -> chosen scope. Absence means the permission is not granted. */
type Draft = Record<string, string>;

/**
 * Configure what a role may do.
 *
 * The permission list is loaded from the backend catalogue — none of the 171
 * codes appear in frontend source, because the registry is the single source of
 * truth and a copy here would drift.
 *
 * A permission is either off, or on with **an explicitly chosen scope**. Nothing
 * defaults to `ALL`: the widest reach should never be what you get by not
 * deciding. Enabling one selects the narrowest scope the backend allows, and the
 * choice is always visible in the row.
 *
 * The scope choices come from the same rules the write endpoint enforces, so
 * `TEAM` is never offered anywhere and a global permission such as `roles.view`
 * shows `ALL` as its only option.
 *
 * Nothing here is a security boundary: every save is authorized again by the
 * backend, which owns the answer.
 */
export function RolePermissionMatrix({ roleId }: { roleId: number }) {
  const t = useTranslations("permissions");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [draft, setDraft] = useState<Draft | null>(null);
  const [saved, setSaved] = useState(false);

  const catalogue = useQuery({
    queryKey: permissionQueryKeys.catalogue,
    queryFn: getPermissionCatalogue,
    retry: (count, error) => toPermissionErrorKey(error) === "server" && count < 2,
  });

  const current = useQuery({
    queryKey: permissionQueryKeys.rolePermissions(roleId),
    queryFn: () => getRolePermissions(roleId),
    retry: (count, error) => toPermissionErrorKey(error) === "server" && count < 2,
  });

  // The saved configuration, as a draft-shaped map.
  const persisted = useMemo<Draft>(() => {
    const map: Draft = {};

    for (const grant of current.data?.permissions ?? []) {
      map[grant.code] = grant.scope;
    }

    return map;
  }, [current.data]);

  const working = draft ?? persisted;

  const dirty = useMemo(() => {
    const codes = new Set([...Object.keys(working), ...Object.keys(persisted)]);

    for (const code of codes) {
      if (working[code] !== persisted[code]) {
        return true;
      }
    }

    return false;
  }, [working, persisted]);

  const mutation = useMutation({
    mutationFn: () =>
      saveRolePermissions(
        roleId,
        Object.entries(working).map(([code, scope]) => ({ code, scope })),
      ),
    onSuccess: async () => {
      setDraft(null);
      setSaved(true);
      await queryClient.invalidateQueries({
        queryKey: permissionQueryKeys.rolePermissions(roleId),
      });
      await queryClient.invalidateQueries({ queryKey: roleQueryKeys.all });
    },
  });

  const update = (code: string, scope: string | null) => {
    setSaved(false);
    setDraft(() => {
      const next = { ...working };

      if (scope === null) {
        delete next[code];
      } else {
        next[code] = scope;
      }

      return next;
    });
  };

  if (catalogue.isPending || current.isPending) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        {[0, 1, 2, 3, 4].map((row) => (
          <Skeleton key={row} className="h-12 w-full" />
        ))}
      </div>
    );
  }

  if (catalogue.isError || current.isError) {
    const errorKey = toPermissionErrorKey(catalogue.error ?? current.error);

    return (
      <BaseErrorState
        title={t(`errorTitles.${errorKey === "forbidden" ? "forbidden" : "generic"}`)}
        description={t(`errors.${errorKey}`)}
        action={
          errorKey === "forbidden" ? undefined : (
            <Button
              variant="outline"
              onClick={() => {
                void catalogue.refetch();
                void current.refetch();
              }}
            >
              {t("retry")}
            </Button>
          )
        }
      />
    );
  }

  const needle = search.trim().toLowerCase();

  const groups = catalogue.data.groups
    .map((group) => ({
      ...group,
      permissions: needle
        ? group.permissions.filter((permission) => permission.code.includes(needle))
        : group.permissions,
    }))
    .filter((group) => group.permissions.length > 0);

  const grantedCount = Object.keys(working).length;
  const malformed = current.data.malformed;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="relative sm:max-w-xs sm:flex-1">
          <Search
            aria-hidden="true"
            className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
          />
          <Input
            type="search"
            className="pl-8"
            placeholder={t("searchPlaceholder")}
            aria-label={t("searchLabel")}
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>

        <div className="flex items-center gap-3">
          <p className="text-muted-foreground text-sm" aria-live="polite">
            {t("grantedSummary", {
              granted: grantedCount,
              total: catalogue.data.groups.reduce(
                (sum, group) => sum + group.permissions.length,
                0,
              ),
            })}
          </p>
          <Button
            variant="outline"
            disabled={!dirty || mutation.isPending}
            onClick={() => setDraft(null)}
          >
            {tActions("cancel")}
          </Button>
          <Button disabled={!dirty || mutation.isPending} onClick={() => mutation.mutate()}>
            {mutation.isPending ? tActions("saving") : tActions("save")}
          </Button>
        </div>
      </div>

      {mutation.isError ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {t(`errors.${toPermissionErrorKey(mutation.error)}`)}
        </p>
      ) : null}

      {saved && !dirty ? (
        <p role="status" className="border-border bg-muted/40 rounded-md border px-3 py-2 text-sm">
          {t("saved")}
        </p>
      ) : null}

      {dirty ? (
        <p role="status" className="border-border bg-muted/40 rounded-md border px-3 py-2 text-sm">
          {t("unsaved")}
        </p>
      ) : null}

      {malformed.length > 0 ? (
        <div
          role="alert"
          className="border-border bg-card flex flex-col gap-2 rounded-lg border p-4 text-sm"
        >
          <div className="flex items-center gap-2 font-medium">
            <TriangleAlert aria-hidden="true" className="text-destructive size-4" />
            {t("malformedTitle")}
          </div>
          <p className="text-muted-foreground max-w-prose">{t("malformedDescription")}</p>
          <ul className="text-muted-foreground list-inside list-disc">
            {malformed.map((grant) => (
              <li key={`${grant.code}-${grant.reason}`}>
                <code>{grant.code}</code> — {t(`malformedReasons.${grant.reason}`)}
                {grant.scope ? ` (${grant.scope})` : ""}
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      {groups.length === 0 ? (
        <div className="border-border bg-card rounded-lg border p-6">
          <p className="text-muted-foreground text-sm">{t("noMatches", { search })}</p>
        </div>
      ) : (
        groups.map((group) => (
          <section key={group.group} className="border-border overflow-hidden rounded-lg border">
            <h2 className="bg-muted/50 text-muted-foreground px-4 py-2.5 text-sm font-medium">
              {t(`groups.${group.group}`)}
            </h2>
            <ul>
              {group.permissions.map((permission) => {
                const scope = working[permission.code];
                const enabled = scope !== undefined;
                const inputId = `permission-${permission.code}`;

                return (
                  <li
                    key={permission.code}
                    className="border-border flex flex-col gap-2 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                  >
                    <div className="flex items-start gap-2.5">
                      <Checkbox
                        id={inputId}
                        checked={enabled}
                        onCheckedChange={(checked) =>
                          update(
                            permission.code,
                            checked === true ? (permission.allowed_scopes[0] ?? null) : null,
                          )
                        }
                      />
                      <div className="flex flex-col gap-0.5">
                        <Label htmlFor={inputId} className="cursor-pointer font-normal">
                          <code>{permission.code}</code>
                        </Label>
                        <div className="flex flex-wrap gap-2">
                          {permission.deferred ? (
                            <span className="text-muted-foreground border-border rounded border px-1.5 py-0.5 text-xs">
                              {t("deferredBadge")}
                            </span>
                          ) : null}
                          {permission.synchronized ? null : (
                            <span className="text-muted-foreground border-border rounded border px-1.5 py-0.5 text-xs">
                              {t("unsyncedBadge")}
                            </span>
                          )}
                        </div>
                        {permission.deferred ? (
                          <p className="text-muted-foreground max-w-prose text-xs">
                            {t("deferredHint")}
                          </p>
                        ) : null}
                      </div>
                    </div>

                    <div className="sm:w-48">
                      <label className="sr-only" htmlFor={`${inputId}-scope`}>
                        {t("scopeLabelFor", { code: permission.code })}
                      </label>
                      <select
                        id={`${inputId}-scope`}
                        disabled={!enabled}
                        value={scope ?? ""}
                        onChange={(event) => update(permission.code, event.target.value)}
                        className={cn(
                          "border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 h-8 w-full rounded-lg border px-2.5 text-sm outline-none focus-visible:ring-3",
                          "disabled:opacity-50",
                        )}
                      >
                        {enabled ? null : <option value="">{t("scopeNone")}</option>}
                        {permission.allowed_scopes.map((allowed) => (
                          <option key={allowed} value={allowed}>
                            {t(`scopes.${allowed}`)}
                          </option>
                        ))}
                      </select>
                    </div>
                  </li>
                );
              })}
            </ul>
          </section>
        ))
      )}

      <div>
        <Button variant="ghost" render={<Link href="/settings/roles" />}>
          <ArrowLeft aria-hidden="true" />
          {t("backToRoles")}
        </Button>
      </div>
    </div>
  );
}
