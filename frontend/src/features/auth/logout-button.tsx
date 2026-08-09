"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { useRouter } from "@/i18n/navigation";
import { logout } from "@/services/auth";

export function LogoutButton() {
  const t = useTranslations("auth");
  const router = useRouter();
  const queryClient = useQueryClient();

  const mutation = useMutation({
    mutationFn: logout,
    // Runs on success and on failure: if the session is already gone the
    // server answers with an error, and the correct outcome is still to drop
    // the cached identity and return to the sign-in screen.
    onSettled: () => {
      queryClient.clear();
      router.replace("/login");
      router.refresh();
    },
  });

  return (
    <Button
      type="button"
      variant="outline"
      onClick={() => mutation.mutate()}
      disabled={mutation.isPending}
    >
      {mutation.isPending ? t("signingOut") : t("signOut")}
    </Button>
  );
}
