"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";

import { usePathname, useRouter } from "@/i18n/navigation";
import { authQueryKeys } from "@/services/auth";
import { profileQueryKeys, updatePreferredLocale } from "@/services/profile";

/**
 * Persist the interface language, then move to the same page in it.
 *
 * **Persist first, navigate second.** Navigating first would leave the
 * interface speaking English while the stored preference silently stayed
 * Indonesian if the request failed — the screen would be lying about what was
 * saved. On failure nothing moves and the caller reports it (D-064).
 *
 * The locale lives in the URL and only there (D-020). This changes the segment
 * once, keeps the logical pathname and query string, and writes no cookie and
 * no browser storage. Choosing a language is a preference for *next* time; it
 * never rewrites a URL somebody typed deliberately.
 *
 * Both the header switcher and the profile page call this, so there is one
 * persistence path rather than two that could diverge.
 */
export function useLocalePreference() {
  const router = useRouter();
  const pathname = usePathname();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (locale: string) => updatePreferredLocale(locale),
    onSuccess: async (_profile, locale) => {
      // The header shows the name and the switcher reads the preference, so
      // both caches are refreshed before the navigation lands.
      await queryClient.invalidateQueries({ queryKey: authQueryKeys.me });
      await queryClient.invalidateQueries({ queryKey: profileQueryKeys.profile });

      // `usePathname()` returns the path without its locale prefix, so this
      // replaces only that segment. Query strings are preserved by reading them
      // from the live location — the router helper does not carry them.
      const search = typeof window === "undefined" ? "" : window.location.search;
      const hash = typeof window === "undefined" ? "" : window.location.hash;

      router.replace(`${pathname}${search}${hash}`, { locale });
      router.refresh();
    },
  });
}
