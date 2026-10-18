"use client";

import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { ButtonLink } from "@/components/ui/button-link";
import { CardHeader } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  MatterDomainBadge,
  MatterPriorityBadge,
  MatterStatusBadge,
} from "@/features/matters/matter-badges";
import { matterCapability } from "@/features/matters/matter-domain";
import { Link } from "@/i18n/navigation";
import { getMatters, matterQueryKeys } from "@/services/matters";
import type { Matter, MatterListQuery } from "@/types/matter";

/**
 * Legal Matters (Notary and PPAT) associated with a Project.
 *
 * **Two domains in one section, querying separate endpoints.**
 * Notary and PPAT answer to separate capabilities (D-101), so each domain is
 * queried under its own namespace and Data Scope. If the caller only holds
 * permissions for one domain, the accessible domain's records are displayed
 * honestly rather than failing completely.
 *
 * Links navigate directly to the specific Matter under its respective domain route
 * (`/notary/matters/{id}` or `/ppat/matters/{id}`).
 */
export function ProjectMattersSection({ projectId }: { projectId: string }) {
  const tProjects = useTranslations("projects");
  const tMatters = useTranslations("matters");
  const tActions = useTranslations("actions");
  const locale = useLocale();

  const [domainFilter, setDomainFilter] = useState<"ALL" | "NOTARY" | "PPAT">("ALL");

  const notaryQuery: MatterListQuery = {
    page: 1,
    per_page: 50,
    search: "",
    status: "",
    priority: "",
    project_id: projectId,
  };

  const ppatQuery: MatterListQuery = {
    page: 1,
    per_page: 50,
    search: "",
    status: "",
    priority: "",
    project_id: projectId,
  };

  const notaryMatters = useQuery({
    queryKey: matterQueryKeys.list("NOTARY", notaryQuery),
    queryFn: () => getMatters("NOTARY", notaryQuery),
  });

  const ppatMatters = useQuery({
    queryKey: matterQueryKeys.list("PPAT", ppatQuery),
    queryFn: () => getMatters("PPAT", ppatQuery),
  });

  const isLoading = notaryMatters.isPending && ppatMatters.isPending;
  const isAllError = notaryMatters.isError && ppatMatters.isError;

  const notaryList = useMemo(() => notaryMatters.data?.data ?? [], [notaryMatters.data?.data]);
  const ppatList = useMemo(() => ppatMatters.data?.data ?? [], [ppatMatters.data?.data]);

  const allMatters: Matter[] = useMemo(() => {
    const combined = [...notaryList, ...ppatList];
    return combined.sort((a, b) => {
      const dateA = a.created_at ?? a.opened_at ?? "";
      const dateB = b.created_at ?? b.opened_at ?? "";
      return dateB.localeCompare(dateA);
    });
  }, [notaryList, ppatList]);

  const displayedMatters = useMemo(() => {
    if (domainFilter === "NOTARY") return notaryList;
    if (domainFilter === "PPAT") return ppatList;
    return allMatters;
  }, [domainFilter, notaryList, ppatList, allMatters]);

  return (
    <div className="flex flex-col gap-4">
      <CardHeader
        title={tProjects("mattersSection")}
        description={tProjects("mattersDescription")}
        action={
          <div className="flex flex-wrap items-center gap-2">
            <PermissionGuard permission={matterCapability("NOTARY", "create")}>
              <ButtonLink
                size="sm"
                variant="outline"
                href={`/notary/matters/new?project_id=${projectId}`}
              >
                {tProjects("addNotaryMatter")}
              </ButtonLink>
            </PermissionGuard>
            <PermissionGuard permission={matterCapability("PPAT", "create")}>
              <ButtonLink
                size="sm"
                variant="outline"
                href={`/ppat/matters/new?project_id=${projectId}`}
              >
                {tProjects("addPpatMatter")}
              </ButtonLink>
            </PermissionGuard>
          </div>
        }
      />

      {isLoading ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{tMatters("loading")}</span>
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      ) : isAllError ? (
        <BaseErrorState
          title={tMatters("errorTitle")}
          description={tMatters("emptyDescription")}
          action={
            <Button
              variant="outline"
              onClick={() => {
                void notaryMatters.refetch();
                void ppatMatters.refetch();
              }}
            >
              {tActions("retry")}
            </Button>
          }
        />
      ) : allMatters.length === 0 ? (
        <div className="border-border flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed py-8 text-center">
          <div className="flex flex-col gap-1">
            <p className="text-foreground font-medium">{tProjects("mattersEmptyTitle")}</p>
            <p className="text-muted-foreground max-w-md text-sm">
              {tProjects("mattersEmptyDescription")}
            </p>
          </div>
          <div className="mt-2 flex flex-wrap items-center justify-center gap-2">
            <PermissionGuard permission={matterCapability("NOTARY", "create")}>
              <ButtonLink
                size="sm"
                variant="outline"
                href={`/notary/matters/new?project_id=${projectId}`}
              >
                {tProjects("addNotaryMatter")}
              </ButtonLink>
            </PermissionGuard>
            <PermissionGuard permission={matterCapability("PPAT", "create")}>
              <ButtonLink
                size="sm"
                variant="outline"
                href={`/ppat/matters/new?project_id=${projectId}`}
              >
                {tProjects("addPpatMatter")}
              </ButtonLink>
            </PermissionGuard>
          </div>
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center gap-2">
            <Button
              size="sm"
              variant={domainFilter === "ALL" ? "default" : "outline"}
              onClick={() => setDomainFilter("ALL")}
            >
              {tProjects("allDomains")} ({allMatters.length})
            </Button>
            <Button
              size="sm"
              variant={domainFilter === "NOTARY" ? "default" : "outline"}
              onClick={() => setDomainFilter("NOTARY")}
            >
              {tProjects("notaryDomain")} ({notaryList.length})
            </Button>
            <Button
              size="sm"
              variant={domainFilter === "PPAT" ? "default" : "outline"}
              onClick={() => setDomainFilter("PPAT")}
            >
              {tProjects("ppatDomain")} ({ppatList.length})
            </Button>
          </div>

          {displayedMatters.length === 0 ? (
            <p className="text-muted-foreground py-4 text-sm">{tMatters("noMatchesDescription")}</p>
          ) : (
            <div className="border-border overflow-x-auto rounded-lg border">
              <table className="w-full text-left text-sm">
                <caption className="sr-only">{tProjects("mattersSection")}</caption>
                <thead className="bg-muted/40 text-muted-foreground">
                  <tr>
                    <th scope="col" className="px-4 py-3 font-medium">
                      {tProjects("matterNumber")}
                    </th>
                    <th scope="col" className="px-4 py-3 font-medium">
                      {tMatters("domainLabel")}
                    </th>
                    <th scope="col" className="px-4 py-3 font-medium">
                      {tMatters("titleLabel")}
                    </th>
                    <th scope="col" className="hidden px-4 py-3 font-medium md:table-cell">
                      {tProjects("serviceType")}
                    </th>
                    <th scope="col" className="px-4 py-3 font-medium">
                      {tMatters("statusLabel")}
                    </th>
                    <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                      {tMatters("priorityLabel")}
                    </th>
                    <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                      {tMatters("picLabel")}
                    </th>
                    <th scope="col" className="hidden px-4 py-3 font-medium xl:table-cell">
                      {tMatters("targetCompletionLabel")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {displayedMatters.map((matter) => {
                    const href =
                      matter.domain === "NOTARY"
                        ? `/notary/matters/${matter.id}`
                        : `/ppat/matters/${matter.id}`;
                    const serviceTypeName =
                      locale === "en" ? matter.service_type?.name_en : matter.service_type?.name_id;

                    return (
                      <tr
                        key={matter.id}
                        className="border-border hover:bg-muted/30 border-t transition-colors"
                      >
                        <td className="text-muted-foreground px-4 py-3 font-mono text-xs whitespace-nowrap">
                          <Link
                            href={href}
                            className="text-foreground font-semibold underline-offset-4 hover:underline"
                          >
                            {matter.matter_number}
                          </Link>
                        </td>
                        <td className="px-4 py-3">
                          <MatterDomainBadge domain={matter.domain} />
                        </td>
                        <td className="px-4 py-3">
                          <Link
                            href={href}
                            className="font-medium underline-offset-4 hover:underline"
                          >
                            {matter.title}
                          </Link>
                        </td>
                        <td className="text-muted-foreground hidden px-4 py-3 md:table-cell">
                          {serviceTypeName ?? "—"}
                        </td>
                        <td className="px-4 py-3">
                          <MatterStatusBadge status={matter.status} />
                        </td>
                        <td className="hidden px-4 py-3 lg:table-cell">
                          <MatterPriorityBadge priority={matter.priority} />
                        </td>
                        <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
                          {matter.pic?.name ?? "—"}
                        </td>
                        <td className="text-muted-foreground hidden px-4 py-3 xl:table-cell">
                          {matter.target_completion_date ?? "—"}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
