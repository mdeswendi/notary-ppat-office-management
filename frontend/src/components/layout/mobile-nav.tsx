"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Menu, X } from "lucide-react";

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
 */
export function MobileNav({ user }: { user: CurrentUser }) {
  const t = useTranslations("navigation");
  const tCommon = useTranslations("common");
  const [open, setOpen] = useState(false);

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

      <SheetContent side="left" showCloseButton={false} className="w-72 p-0">
        <SheetHeader className="flex-row items-center justify-between gap-2 p-3">
          <SheetTitle className="text-sm font-semibold">{tCommon("officeLabel")}</SheetTitle>
          <SheetClose
            render={
              <Button variant="ghost" size="icon-sm" aria-label={t("closeNavigation")}>
                <X aria-hidden="true" />
              </Button>
            }
          />
        </SheetHeader>

        <SheetDescription className="sr-only">{t("mainLabel")}</SheetDescription>

        <Separator />

        <nav aria-label={t("mainLabel")} className="p-3">
          <SidebarNav user={user} onNavigate={() => setOpen(false)} />
        </nav>
      </SheetContent>
    </Sheet>
  );
}
