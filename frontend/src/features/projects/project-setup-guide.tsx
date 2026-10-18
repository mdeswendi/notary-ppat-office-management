"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Circle,
  Compass,
  FileText,
  Plus,
  UserPlus,
} from "lucide-react";
import { useTranslations } from "next-intl";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ButtonLink } from "@/components/ui/button-link";
import { Card } from "@/components/ui/card";
import { getDocuments } from "@/services/documents";
import { getMatters } from "@/services/matters";
import { getProjectParties, projectPartyQueryKeys } from "@/services/project-parties";

/**
 * Interactive setup checklist guide displayed on the Project detail page.
 *
 * Helps office staff easily follow the essential setup steps after creating a Project:
 * 1. Project identity & registration (automatic)
 * 2. Connecting client & parties
 * 3. Starting legal matters (Notary or PPAT)
 * 4. Attaching supporting documents
 *
 * Each step dynamically reflects live project state and provides direct action shortcuts.
 */
export function ProjectSetupGuide({
  projectId,
  onAddPartyClick,
  onNavigateTab,
}: {
  projectId: string;
  onAddPartyClick?: () => void;
  onNavigateTab?: (tab: string, anchorId?: string) => void;
}) {
  const t = useTranslations("projects.setupGuide");
  const [collapsed, setCollapsed] = useState(false);

  const partiesQuery = useQuery({
    queryKey: projectPartyQueryKeys.all(projectId),
    queryFn: () => getProjectParties(projectId),
  });

  const notaryMattersQuery = useQuery({
    queryKey: ["project-matters-check", "NOTARY", projectId],
    queryFn: () =>
      getMatters("NOTARY", {
        page: 1,
        per_page: 1,
        search: "",
        status: "",
        priority: "",
        project_id: projectId,
      }),
  });

  const ppatMattersQuery = useQuery({
    queryKey: ["project-matters-check", "PPAT", projectId],
    queryFn: () =>
      getMatters("PPAT", {
        page: 1,
        per_page: 1,
        search: "",
        status: "",
        priority: "",
        project_id: projectId,
      }),
  });

  const documentsQuery = useQuery({
    queryKey: ["project-documents-check", projectId],
    queryFn: () => getDocuments({ project_id: projectId, per_page: 1 }),
  });

  const partyCount = partiesQuery.data?.data?.length ?? 0;
  const hasParties = partyCount > 0;

  const notaryCount = notaryMattersQuery.data?.meta?.total ?? 0;
  const ppatCount = ppatMattersQuery.data?.meta?.total ?? 0;
  const hasMatters = notaryCount > 0 || ppatCount > 0;

  const documentCount = documentsQuery.data?.meta?.total ?? 0;
  const hasDocuments = documentCount > 0;

  const scrollToParties = () => {
    if (onAddPartyClick) {
      onAddPartyClick();
      return;
    }
    if (onNavigateTab) {
      onNavigateTab("parties", "project-parties");
      return;
    }
    const element = document.getElementById("project-parties");
    if (element) {
      element.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  };

  const scrollToDocuments = () => {
    if (onNavigateTab) {
      onNavigateTab("documents", "project-documents");
      return;
    }
    const element = document.getElementById("project-documents");
    if (element) {
      element.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  };

  const steps = [
    {
      id: "project-info",
      title: t("step1Title"),
      description: t("step1Desc"),
      completed: true,
      actions: null,
    },
    {
      id: "parties",
      title: t("step2Title"),
      description: hasParties
        ? t("step2DescCompleted", { count: partyCount })
        : t("step2DescPending"),
      completed: hasParties,
      actions: !hasParties ? (
        <Button variant="outline" size="sm" onClick={scrollToParties}>
          <UserPlus className="mr-1.5 h-3.5 w-3.5" />
          {t("step2Action")}
        </Button>
      ) : null,
    },
    {
      id: "matters",
      title: t("step3Title"),
      description: hasMatters ? t("step3DescCompleted") : t("step3DescPending"),
      completed: hasMatters,
      actions: !hasMatters ? (
        <div className="flex flex-wrap gap-2">
          <ButtonLink
            variant="outline"
            size="sm"
            href={`/notary/matters/new?project_id=${projectId}`}
          >
            <Plus className="mr-1 h-3.5 w-3.5" />
            {t("step3NotaryAction")}
          </ButtonLink>
          <ButtonLink
            variant="outline"
            size="sm"
            href={`/ppat/matters/new?project_id=${projectId}`}
          >
            <Plus className="mr-1 h-3.5 w-3.5" />
            {t("step3PpatAction")}
          </ButtonLink>
        </div>
      ) : null,
    },
    {
      id: "documents",
      title: t("step4Title"),
      description: hasDocuments ? t("step4DescCompleted") : t("step4DescPending"),
      completed: hasDocuments,
      actions: !hasDocuments ? (
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" onClick={scrollToDocuments}>
            <FileText className="mr-1.5 h-3.5 w-3.5" />
            {t("step4Action")}
          </Button>
        </div>
      ) : null,
    },
  ];

  const completedCount = steps.filter((s) => s.completed).length;
  const isAllCompleted = completedCount === steps.length;
  const progressPercent = Math.round((completedCount / steps.length) * 100);

  return (
    <Card className="border-primary/20 bg-card/60 p-5">
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <div className="bg-primary/10 text-primary flex h-9 w-9 items-center justify-center rounded-lg">
              <Compass className="h-5 w-5" />
            </div>
            <div>
              <h2 className="text-base font-semibold tracking-tight">{t("title")}</h2>
              <p className="text-muted-foreground text-xs">{t("subtitle")}</p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Badge tone={isAllCompleted ? "ppat" : "primary"}>
              {t("progress", { completed: completedCount, total: steps.length })}
            </Badge>

            <Button
              variant="ghost"
              size="sm"
              onClick={() => setCollapsed(!collapsed)}
              className="text-muted-foreground h-8 px-2 text-xs"
              aria-label={collapsed ? t("expandAction") : t("collapseAction")}
            >
              {collapsed ? (
                <>
                  <ChevronDown className="mr-1 h-3.5 w-3.5" />
                  {t("expandAction")}
                </>
              ) : (
                <>
                  <ChevronUp className="mr-1 h-3.5 w-3.5" />
                  {t("collapseAction")}
                </>
              )}
            </Button>
          </div>
        </div>

        <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
          <div
            className="bg-primary h-full transition-all duration-300 ease-in-out"
            style={{ width: `${progressPercent}%` }}
          />
        </div>

        {!collapsed && (
          <div className="mt-1 flex flex-col gap-3">
            {isAllCompleted ? (
              <div className="border-border/60 bg-muted/40 flex items-start gap-3 rounded-lg border p-3">
                <CheckCircle2 className="text-primary mt-0.5 h-5 w-5 shrink-0" />
                <div>
                  <h3 className="text-sm font-medium">{t("allCompletedTitle")}</h3>
                  <p className="text-muted-foreground text-xs">{t("allCompletedDescription")}</p>
                </div>
              </div>
            ) : null}

            <div className="grid gap-3 sm:grid-cols-2">
              {steps.map((step) => (
                <div
                  key={step.id}
                  className={`border-border/60 bg-background/80 flex flex-col justify-between rounded-lg border p-3.5 transition-colors ${
                    step.completed ? "border-primary/30" : ""
                  }`}
                >
                  <div className="flex items-start gap-2.5">
                    {step.completed ? (
                      <CheckCircle2 className="text-primary mt-0.5 h-4 w-4 shrink-0" />
                    ) : (
                      <Circle className="text-muted-foreground mt-0.5 h-4 w-4 shrink-0" />
                    )}
                    <div className="flex flex-col gap-0.5">
                      <span
                        className={`text-sm font-medium ${
                          step.completed ? "text-foreground" : "text-muted-foreground"
                        }`}
                      >
                        {step.title}
                      </span>
                      <span className="text-muted-foreground text-xs leading-relaxed">
                        {step.description}
                      </span>
                    </div>
                  </div>

                  {step.actions ? <div className="mt-3 pl-6.5">{step.actions}</div> : null}
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </Card>
  );
}
