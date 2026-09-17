"use client";

import type { ComponentProps, MouseEvent } from "react";
import { ArrowLeft } from "lucide-react";
import { useTranslations } from "next-intl";

import { ButtonLink } from "@/components/ui/button-link";

type PageBackLinkProps = Pick<ComponentProps<typeof ButtonLink>, "href">;

/** Returns to in-app history when safe, with a predictable parent-route fallback. */
export function PageBackLink({ href }: PageBackLinkProps) {
  const t = useTranslations("common");

  function handleClick(event: MouseEvent<HTMLAnchorElement>) {
    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return;
    }

    // Prefer the page the user actually came from, but only when it belongs to
    // this application. A direct visit, bookmark, installed PWA, or external
    // referrer keeps the explicit `href` as a safe and predictable fallback.
    if (!document.referrer) {
      return;
    }

    const previousUrl = new URL(document.referrer);

    if (
      previousUrl.origin !== window.location.origin ||
      previousUrl.href === window.location.href
    ) {
      return;
    }

    event.preventDefault();
    window.history.back();
  }

  return (
    <ButtonLink
      href={href}
      onClick={handleClick}
      variant="outline"
      size="sm"
      className="group/back text-muted-foreground hover:text-foreground border-border/70 bg-background/80 hover:border-border hover:bg-muted/70 h-9 w-fit gap-2 rounded-full px-3 text-sm shadow-xs backdrop-blur-sm transition-[color,background-color,border-color,box-shadow,transform] duration-200 ease-out hover:-translate-y-px hover:shadow-sm active:translate-y-0 motion-reduce:transform-none motion-reduce:transition-none"
    >
      <span className="inline-flex transition-transform duration-200 ease-out group-hover/back:-translate-x-0.5 motion-reduce:transform-none motion-reduce:transition-none">
        <ArrowLeft aria-hidden="true" />
      </span>
      {t("back")}
    </ButtonLink>
  );
}
