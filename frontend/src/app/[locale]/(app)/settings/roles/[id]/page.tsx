import { notFound } from "next/navigation";
import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
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
      <PageHeader title={t("title")} description={t("subtitle")} />

      <RolePermissionMatrix roleId={roleId} />
    </PageContainer>
  );
}
