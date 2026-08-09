"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useFormatter, useTranslations } from "next-intl";
import { Pencil, Plus, Trash2 } from "lucide-react";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { DeleteRoleDialog } from "@/features/roles/delete-role-dialog";
import { RoleFormDialog } from "@/features/roles/role-form-dialog";
import { toRoleErrorKey } from "@/features/roles/role-errors";
import { getRoles, roleQueryKeys } from "@/services/roles";
import type { Role } from "@/types/role";

/**
 * The role list, with its create, rename, and delete affordances.
 *
 * The list is whatever the API returns. The nine documented default roles are
 * deliberately not seeded into the interface: showing names the database does
 * not contain would be inventing data, and their provisioning belongs to the
 * deployment bootstrap.
 *
 * Not paginated, because the backend does not paginate it — roles are
 * deployment-global configuration bounded by administrative action, not an
 * operational dataset.
 *
 * Nothing here is hidden based on the browser's permission list. Role
 * administration requires a canonical `roles.*` permission at the `ALL` Data
 * Scope, a condition the permission names in `/api/v1/me` cannot express, so
 * the page asks the backend and renders whatever it answers. The API is the
 * security boundary either way.
 */
export function RolesList() {
  const t = useTranslations("roles");
  const format = useFormatter();

  // The dialogs are mounted only while open, so each one starts from clean
  // state without resetting anything in an effect. `null` means closed;
  // `{ role: null }` means creating.
  const [form, setForm] = useState<{ role: Role | null } | null>(null);
  const [deleting, setDeleting] = useState<Role | null>(null);

  const query = useQuery({
    queryKey: roleQueryKeys.all,
    queryFn: getRoles,
    // A 403 is a settled answer about this account, not a blip worth retrying.
    retry: (failureCount, error) => toRoleErrorKey(error) === "server" && failureCount < 2,
  });

  const openCreate = () => setForm({ role: null });

  const openEdit = (role: Role) => setForm({ role });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        {[0, 1, 2].map((row) => (
          <Skeleton key={row} className="h-14 w-full" />
        ))}
      </div>
    );
  }

  if (query.isError) {
    const errorKey = toRoleErrorKey(query.error);

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

  const roles = query.data;

  return (
    <>
      <div className="flex items-center justify-end">
        <Button onClick={openCreate}>
          <Plus aria-hidden="true" />
          {t("create")}
        </Button>
      </div>

      {roles.length === 0 ? (
        <div className="border-border bg-card flex flex-col items-start gap-2 rounded-lg border p-6">
          <h2 className="text-base font-medium">{t("emptyTitle")}</h2>
          <p className="text-muted-foreground max-w-prose text-sm">{t("emptyDescription")}</p>
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
                <th scope="col" className="hidden px-4 py-2.5 text-left font-medium sm:table-cell">
                  {t("createdAtLabel")}
                </th>
                <th scope="col" className="px-4 py-2.5 text-right font-medium">
                  <span className="sr-only">{t("rowActions")}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {roles.map((role) => (
                <tr key={role.id} className="border-border border-t">
                  <td className="px-4 py-3 font-medium">{role.name}</td>
                  <td className="text-muted-foreground hidden px-4 py-3 sm:table-cell">
                    {role.created_at
                      ? format.dateTime(new Date(role.created_at), {
                          dateStyle: "medium",
                        })
                      : "—"}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex justify-end gap-1">
                      <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={t("editAria", { name: role.name })}
                        onClick={() => openEdit(role)}
                      >
                        <Pencil aria-hidden="true" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={t("deleteAria", { name: role.name })}
                        onClick={() => setDeleting(role)}
                      >
                        <Trash2 aria-hidden="true" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {form ? (
        <RoleFormDialog
          key={form.role?.id ?? "create"}
          role={form.role}
          onClose={() => setForm(null)}
        />
      ) : null}

      {deleting ? (
        <DeleteRoleDialog key={deleting.id} role={deleting} onClose={() => setDeleting(null)} />
      ) : null}
    </>
  );
}
