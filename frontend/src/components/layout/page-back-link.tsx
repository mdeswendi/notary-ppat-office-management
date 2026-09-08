"use client";

import type { ComponentProps } from "react";
import { ArrowLeft } from "lucide-react";
import { useTranslations } from "next-intl";

import { ButtonLink } from "@/components/ui/button-link";

type PageBackLinkProps = Pick<ComponentProps<typeof ButtonLink>, "href">;

/** A predictable route back from create, edit, and other secondary pages. */
export function PageBackLink({ href }: PageBackLinkProps) {
  const t = useTranslations("common");

  return (
    <ButtonLink
      href={href}
      variant="ghost"
      size="sm"
      className="text-muted-foreground -ml-2 w-fit gap-1.5"
    >
      <ArrowLeft aria-hidden="true" />
      {t("back")}
    </ButtonLink>
  );
}
