import { notFound } from "next/navigation";
import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { RolePermissionMatrix } from "@/features/permissions/role-permission-matrix";

/**
 * Permission Matrix for one role.
 *
 * Authorization is the API's: reading and saving need `permissions.view` and
 * `permissions.assign` at the `ALL` Data Scope, which the browser cannot
 * evaluate, so the matrix asks and renders a forbidden state if refused.
 *
 * The role id is the package's integer key, validated here only enough to avoid
 * sending nonsense to the API.
 */
export default async function RolePermissionsPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;

  const roleId = Number(id);

  if (!Number.isInteger(roleId) || roleId <= 0) {
    notFound();
  }

  const t = await getTranslations({ locale, namespace: "permissions" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-muted-foreground">{t("subtitle")}</p>
      </div>

      <RolePermissionMatrix roleId={roleId} />
    </PageContainer>
  );
}
