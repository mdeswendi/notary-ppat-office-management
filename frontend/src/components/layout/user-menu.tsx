"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { Languages, LogOut, ShieldCheck, UserRound } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Link, useRouter } from "@/i18n/navigation";
import { logout } from "@/services/auth";
import type { CurrentUser } from "@/types/auth";

/**
 * Authenticated account menu.
 *
 * Shows the name and email the backend already returned from `/api/v1/me`, and
 * nothing else: no ULID, no roles, no permission list, no account metadata.
 * There is no profile or settings entry because no such route exists — a menu
 * item leading nowhere is worse than an absent one.
 *
 * Sign out reuses the M0.7 flow verbatim.
 */
export function UserMenu({ user }: { user: CurrentUser }) {
  const t = useTranslations("auth");
  const router = useRouter();
  const queryClient = useQueryClient();

  const signOut = useMutation({
    mutationFn: logout,
    // Runs on success and failure alike: if the session is already gone the
    // right outcome is still to drop the cached identity and return to login.
    onSettled: () => {
      queryClient.clear();
      router.replace("/login");
      router.refresh();
    },
  });

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={
          <Button variant="ghost" size="sm" className="gap-2" aria-label={t("accountMenu")}>
            <UserRound aria-hidden="true" />
            <span className="hidden max-w-40 truncate sm:inline">{user.name}</span>
          </Button>
        }
      />

      <DropdownMenuContent align="end" className="w-56">
        <div className="flex flex-col gap-0.5 px-2 py-1.5">
          <span className="truncate text-sm font-medium">{user.name}</span>
          <span className="text-muted-foreground truncate text-xs">{user.email}</span>
        </div>

        <DropdownMenuSeparator />

        {/* Available to everyone signed in — self-service needs no permission
            (D-066, D-071). Preferences points at the language section of the
            same page rather than a second screen that would only hold one
            control.

            Security is a sibling of Profile, not a Settings destination:
            Settings administers other people, and this is the opposite. */}
        <DropdownMenuItem render={<Link href="/profile" />} className="gap-2">
          <UserRound aria-hidden="true" />
          {t("myProfile")}
        </DropdownMenuItem>

        <DropdownMenuItem render={<Link href="/security" />} className="gap-2">
          <ShieldCheck aria-hidden="true" />
          {t("accountSecurity")}
        </DropdownMenuItem>

        <DropdownMenuItem render={<Link href="/profile#preferences" />} className="gap-2">
          <Languages aria-hidden="true" />
          {t("preferences")}
        </DropdownMenuItem>

        <DropdownMenuSeparator />

        <DropdownMenuItem
          onClick={() => signOut.mutate()}
          disabled={signOut.isPending}
          className="gap-2"
        >
          <LogOut aria-hidden="true" />
          {signOut.isPending ? t("signingOut") : t("signOut")}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
