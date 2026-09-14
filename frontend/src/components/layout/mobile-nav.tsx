"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { House, Leaf, Menu, X } from "lucide-react";

import { SidebarNav } from "@/components/layout/sidebar-nav";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import { resolveOfficeIdentity } from "@/lib/office-identity";
import type { CurrentUser } from "@/types/auth";

/**
 * Navigation drawer for viewports below `lg`, completing the responsive shell
 * that M0.6 deferred.
 *
 * Renders the same `SidebarNav` as the desktop sidebar, so there is one menu
 * definition rather than two that can drift apart. Choosing a destination
 * closes the drawer.
 *
 * The built-in close button is suppressed in favour of a translated one — the
 * vendored primitive hardcodes an English "Close" label.
 *
 * `nav` is the only child given `flex-1 overflow-y-auto`, the same pairing
 * `AppSidebar` already uses. `SheetContent` is `fixed inset-y-0 h-full flex
 * flex-col` — a genuinely bounded height, not one the content grows to fit —
 * so this is the one child that should absorb the remainder of that height
 * and scroll, while the header and close control stay put above it. Without
 * it, a list taller than the sheet had nothing to shrink or scroll, and the
 * bottom of the menu was simply unreachable below `lg`.
 */
export function MobileNav({ user }: { user: CurrentUser }) {
  const t = useTranslations("navigation");
  const tCommon = useTranslations("common");
  const [open, setOpen] = useState(false);
  const officeIdentity = resolveOfficeIdentity(user.office) ?? tCommon("officeLabel");
  const isPpatPractice = user.office?.practice_type === "PPAT";
  const brandHeading = isPpatPractice ? tCommon("officeLabel") : null;
  const brandName = isPpatPractice
    ? officeIdentity.replace(/^Kantor\s+PPAT\s*/i, "")
    : officeIdentity;

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger
        render={
          <Button
            variant="ghost"
            size="icon"
            className="lg:hidden"
            aria-label={t("openNavigation")}
          >
            <Menu aria-hidden="true" />
          </Button>
        }
      />

      <SheetContent
        side="left"
        showCloseButton={false}
        className="border-sidebar-border bg-sidebar text-sidebar-foreground supports-[backdrop-filter]:bg-sidebar/94 w-72 p-0 shadow-2xl supports-[backdrop-filter]:backdrop-blur-xl"
      >
        <SheetHeader className="flex-row items-start justify-between gap-3 px-5 pt-5 pb-4">
          <SheetTitle className="flex min-w-0 flex-1 items-center gap-3 text-left">
            <span aria-hidden="true" className="text-brand-gold relative block size-11 shrink-0">
              <House className="absolute inset-0 size-11 stroke-[1.4]" />
              <Leaf className="absolute right-0.5 bottom-0.5 size-5 -rotate-12 stroke-[1.7]" />
            </span>
            <span className="min-w-0">
              <span className="text-sidebar-foreground block font-serif leading-tight font-semibold">
                {brandHeading ? <span className="block text-sm">{brandHeading}</span> : null}
                <span className="block text-xs">{brandName}</span>
              </span>
              <span className="text-brand-gold mt-1 block text-[8px] leading-relaxed font-semibold tracking-[0.08em] uppercase">
                {tCommon("sidebarBrandTagline")}
              </span>
            </span>
          </SheetTitle>
          <SheetClose
            render={
              <Button
                variant="ghost"
                size="icon-sm"
                className="text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                aria-label={t("closeNavigation")}
              >
                <X aria-hidden="true" />
              </Button>
            }
          />
        </SheetHeader>

        <SheetDescription className="sr-only">{t("mainLabel")}</SheetDescription>

        <Separator />

        <nav aria-label={t("mainLabel")} className="flex-1 overflow-y-auto overscroll-contain p-3">
          <SidebarNav user={user} onNavigate={() => setOpen(false)} />
        </nav>
      </SheetContent>
    </Sheet>
  );
}
