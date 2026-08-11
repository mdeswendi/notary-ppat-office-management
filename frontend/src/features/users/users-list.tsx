"use client";

import { useEffect, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { KeyRound, LockKeyhole, Pencil, Plus, Search, ShieldCheck, ShieldOff } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { UserRolesDialog } from "@/features/permissions/user-roles-dialog";
import { UserActivationDialog } from "@/features/users/user-activation-dialog";
import { UserFormDialog } from "@/features/users/user-form-dialog";
import { UserSecurityDialog } from "@/features/users/user-security-dialog";
import { toUserErrorKey } from "@/features/users/user-errors";
import { getUsers, userQueryKeys } from "@/services/users";
import type { ManagedUser } from "@/types/user";

/**
 * The user list, with search, pagination, and the administrative actions.
 *
 * Rows are whatever the API returns. Visibility is decided server-side by Data
 * Scope — an office-scoped administrator's request never selects another
 * Office's rows — so this component does no filtering of its own and the total
 * it shows is already the total that caller may see.
 *
 * Nothing is hidden based on the browser's permission list: it cannot express
 * "at which scope" (O-026). The page asks the backend and renders the answer,
 * including a forbidden state. The API is the security boundary regardless.
 */
export function UsersList() {
  const t = useTranslations("users");

  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");

  const [form, setForm] = useState<{ user: ManagedUser | null } | null>(null);
  const [activation, setActivation] = useState<{ user: ManagedUser; activate: boolean } | null>(
    null,
  );
  const [roles, setRoles] = useState<ManagedUser | null>(null);
  const [security, setSecurity] = useState<ManagedUser | null>(null);

  // Debounced so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const query = useQuery({
    queryKey: userQueryKeys.list({ page, search }),
    queryFn: () => getUsers({ page, search }),
    // Keeps the previous page visible while the next one loads, so paging does
    // not blink back to skeletons.
    placeholderData: keepPreviousData,
    retry: (failureCount, error) => toUserErrorKey(error) === "server" && failureCount < 2,
  });

  if (query.isError) {
    const errorKey = toUserErrorKey(query.error);

    return (
      <BaseErrorState
        title={t(`errorTitles.${errorKey === "forbidden" ? "forbidden" : "generic"}`)}
        description={t(`errors.${errorKey}`)}
        action={
          errorKey === "forbidden" ? undefined : (
            <Button variant="outline" onClick={() => void query.refetch()}>
              {t("retry")}
            </Button>
          )
        }
      />
    );
  }

  const users = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <>
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
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
          />
        </div>

        {/* Only the capability is checked here. Whether a *particular* target
            is within the actor's Office is a Policy predicate, and duplicating
            it in React would be a second authorization engine (D-063). */}
        <PermissionGuard permission="users.create">
          <Button onClick={() => setForm({ user: null })}>
            <Plus aria-hidden="true" />
            {t("create")}
          </Button>
        </PermissionGuard>
      </div>

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          {[0, 1, 2, 3].map((row) => (
            <Skeleton key={row} className="h-14 w-full" />
          ))}
        </div>
      ) : users.length === 0 ? (
        <div className="border-border bg-card flex flex-col items-start gap-2 rounded-lg border p-6">
          <h2 className="text-base font-medium">
            {search ? t("noMatchesTitle") : t("emptyTitle")}
          </h2>
          <p className="text-muted-foreground max-w-prose text-sm">
            {search ? t("noMatchesDescription", { search }) : t("emptyDescription")}
          </p>
        </div>
      ) : (
        <div className="border-border overflow-x-auto rounded-lg border">
          <table className="w-full text-sm">
            <caption className="sr-only">{t("tableCaption")}</caption>
            <thead className="bg-muted/50 text-muted-foreground">
              <tr>
                <th scope="col" className="px-4 py-2.5 text-left font-medium">
                  {t("nameLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-2.5 text-left font-medium md:table-cell">
                  {t("officeLabel")}
                </th>
                <th scope="col" className="px-4 py-2.5 text-left font-medium">
                  {t("statusLabel")}
                </th>
                <th scope="col" className="px-4 py-2.5 text-right font-medium">
                  <span className="sr-only">{t("rowActions")}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {users.map((user) => (
                <tr key={user.id} className="border-border border-t">
                  <td className="px-4 py-3">
                    <div className="font-medium">{user.name}</div>
                    <div className="text-muted-foreground">{user.email}</div>
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 md:table-cell">
                    {user.office ? `${user.office.code} — ${user.office.name}` : "—"}
                  </td>
                  <td className="px-4 py-3">
                    {/* Status is not carried by colour alone: each state has a
                        word and a distinct icon. */}
                    <span
                      className={
                        user.is_active
                          ? "inline-flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400"
                          : "text-muted-foreground inline-flex items-center gap-1.5"
                      }
                    >
                      {user.is_active ? (
                        <ShieldCheck aria-hidden="true" className="size-3.5" />
                      ) : (
                        <ShieldOff aria-hidden="true" className="size-3.5" />
                      )}
                      {user.is_active ? t("statusActive") : t("statusInactive")}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex justify-end gap-1">
                      <PermissionGuard permission="users.update">
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={t("editAria", { name: user.name })}
                          onClick={() => setForm({ user })}
                        >
                          <Pencil aria-hidden="true" />
                        </Button>
                      </PermissionGuard>
                      {/* Membership is permission administration, so it needs
                          permissions.view at ALL — not users.update (D-055). */}
                      <PermissionGuard permission="permissions.view" scope="ALL">
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={t("rolesAria", { name: user.name })}
                          onClick={() => setRoles(user)}
                        >
                          <KeyRound aria-hidden="true" />
                        </Button>
                      </PermissionGuard>
                      <PermissionGuard permission="users.disable">
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={
                            user.is_active
                              ? t("disableAria", { name: user.name })
                              : t("enableAria", { name: user.name })
                          }
                          onClick={() => setActivation({ user, activate: !user.is_active })}
                        >
                          {user.is_active ? (
                            <ShieldOff aria-hidden="true" />
                          ) : (
                            <ShieldCheck aria-hidden="true" />
                          )}
                        </Button>
                      </PermissionGuard>
                      {/* Account security is its own set of capabilities, not a
                          corner of user editing: password reset, session
                          revocation, and two-factor removal each answer to a
                          different permission (D-071). The dialog shows only
                          the parts this account may use. */}
                      <PermissionGuard permission="users.reset_password">
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={t("security.openAria", { name: user.name })}
                          onClick={() => setSecurity(user)}
                        >
                          <LockKeyhole aria-hidden="true" />
                        </Button>
                      </PermissionGuard>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta && meta.last_page > 1 ? (
        <nav className="flex items-center justify-between gap-3" aria-label={t("paginationLabel")}>
          <p className="text-muted-foreground text-sm">
            {t("paginationSummary", {
              current: meta.current_page,
              last: meta.last_page,
              total: meta.total,
            })}
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page <= 1 || query.isFetching}
              onClick={() => setPage((current) => Math.max(1, current - 1))}
            >
              {t("previousPage")}
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page >= meta.last_page || query.isFetching}
              onClick={() => setPage((current) => current + 1)}
            >
              {t("nextPage")}
            </Button>
          </div>
        </nav>
      ) : null}

      {form ? (
        <UserFormDialog
          key={form.user?.id ?? "create"}
          user={form.user}
          onClose={() => setForm(null)}
        />
      ) : null}

      {activation ? (
        <UserActivationDialog
          key={`${activation.user.id}-${activation.activate}`}
          user={activation.user}
          activate={activation.activate}
          onClose={() => setActivation(null)}
        />
      ) : null}

      {roles ? (
        <UserRolesDialog key={roles.id} user={roles} onClose={() => setRoles(null)} />
      ) : null}

      {security ? (
        <UserSecurityDialog key={security.id} user={security} onClose={() => setSecurity(null)} />
      ) : null}
    </>
  );
}
