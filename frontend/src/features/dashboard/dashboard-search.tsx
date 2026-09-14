"use client";

import { useQuery } from "@tanstack/react-query";
import { FileSignature, FolderSearch, LoaderCircle, MapPinned, Search, Users } from "lucide-react";
import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";

import { useCurrentUser } from "@/features/auth/use-current-user";
import { Link } from "@/i18n/navigation";
import { can } from "@/lib/permissions/can";
import { getMatters } from "@/services/matters";
import { getPartyDirectory } from "@/services/parties";
import { getPpatDeeds } from "@/services/ppat";
import { getProperties } from "@/services/properties";

type SearchResult = {
  id: string;
  type: "party" | "deed" | "property" | "matter";
  title: string;
  subtitle: string;
  href: string;
};

const RESULT_ICONS = {
  party: Users,
  deed: FileSignature,
  property: MapPinned,
  matter: FolderSearch,
} as const;

/** Searches the four real PPAT work surfaces while preserving their own scopes. */
export function DashboardSearch() {
  const t = useTranslations("dashboard.search");
  const { data: user } = useCurrentUser();
  const [value, setValue] = useState("");
  const [focused, setFocused] = useState(false);
  const debouncedValue = useDebouncedValue(value, 300);
  const term = debouncedValue.trim();

  const access = {
    parties: can(user, "parties.view") || can(user, "companies.view"),
    deeds: can(user, "ppat.deeds.view"),
    properties: can(user, "properties.view"),
    matters: can(user, "ppat.matters.view"),
  };
  const hasAccess = Object.values(access).some(Boolean);

  const query = useQuery({
    queryKey: ["dashboard", "search", term, access],
    queryFn: () => searchDashboard(term, access),
    enabled: term.length >= 2 && hasAccess,
    staleTime: 30_000,
  });

  const showResults = focused && value.trim().length >= 2;

  if (user && !hasAccess) {
    return null;
  }

  return (
    <div className="relative z-20 w-full max-w-xl">
      <label htmlFor="dashboard-search" className="sr-only">
        {t("label")}
      </label>
      <div className="border-border bg-card focus-within:ring-ring/30 flex h-10 items-center gap-2 rounded-lg border px-3 shadow-sm focus-within:ring-2">
        {query.isFetching ? (
          <LoaderCircle
            className="text-muted-foreground size-4 shrink-0 animate-spin"
            aria-hidden="true"
          />
        ) : (
          <Search className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
        )}
        <input
          id="dashboard-search"
          type="search"
          autoComplete="off"
          value={value}
          onChange={(event) => setValue(event.target.value)}
          onFocus={() => setFocused(true)}
          onBlur={() => window.setTimeout(() => setFocused(false), 120)}
          onKeyDown={(event) => {
            if (event.key === "Escape") {
              setFocused(false);
              event.currentTarget.blur();
            }
          }}
          placeholder={t("placeholder")}
          className="placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-sm outline-none"
          role="combobox"
          aria-autocomplete="list"
          aria-expanded={showResults}
          aria-controls="dashboard-search-results"
        />
      </div>

      {showResults ? (
        <div
          id="dashboard-search-results"
          role="listbox"
          className="border-border bg-popover absolute top-12 right-0 left-0 max-h-96 overflow-y-auto rounded-lg border p-1.5 shadow-xl"
        >
          {query.isPending || query.isFetching ? (
            <p className="text-muted-foreground px-3 py-4 text-sm">{t("loading")}</p>
          ) : query.isError ? (
            <p className="text-muted-foreground px-3 py-4 text-sm">{t("unavailable")}</p>
          ) : query.data.length === 0 ? (
            <p className="text-muted-foreground px-3 py-4 text-sm">{t("empty")}</p>
          ) : (
            <ul>
              {query.data.map((result) => {
                const Icon = RESULT_ICONS[result.type];

                return (
                  <li key={`${result.type}-${result.id}`} role="option" aria-selected="false">
                    <Link
                      href={result.href}
                      className="hover:bg-accent focus-visible:bg-accent flex items-start gap-3 rounded-md px-3 py-2.5 outline-none"
                    >
                      <span className="bg-secondary text-primary mt-0.5 grid size-8 shrink-0 place-items-center rounded-md">
                        <Icon className="size-4" aria-hidden="true" />
                      </span>
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-medium">{result.title}</span>
                        <span className="text-muted-foreground block truncate text-xs">
                          {t(`types.${result.type}`)} · {result.subtitle}
                        </span>
                      </span>
                    </Link>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      ) : null}
    </div>
  );
}

function useDebouncedValue(value: string, delay: number): string {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timeout = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(timeout);
  }, [delay, value]);

  return debounced;
}

async function searchDashboard(
  term: string,
  access: { parties: boolean; deeds: boolean; properties: boolean; matters: boolean },
): Promise<SearchResult[]> {
  const requests = [
    access.parties
      ? getPartyDirectory({ page: 1, per_page: 5, search: term, party_type: "", office_id: "" })
      : Promise.resolve(null),
    access.deeds
      ? getPpatDeeds({ page: 1, per_page: 5, search: term, status: "" })
      : Promise.resolve(null),
    access.properties
      ? getProperties({ page: 1, per_page: 5, search: term, archived: "" })
      : Promise.resolve(null),
    access.matters
      ? getMatters("PPAT", {
          page: 1,
          per_page: 5,
          search: term,
          status: "",
          priority: "",
          project_id: "",
        })
      : Promise.resolve(null),
  ] as const;

  const [parties, deeds, properties, matters] = await Promise.allSettled(requests);
  const results: SearchResult[] = [];

  if (parties.status === "fulfilled" && parties.value) {
    for (const party of parties.value.data) {
      results.push({
        id: party.id,
        type: "party",
        title: party.display_name ?? "—",
        subtitle: party.primary_phone ?? party.primary_email ?? "—",
        href:
          party.party_type === "COMPANY"
            ? `/parties/companies/${party.id}`
            : `/parties/individuals/${party.id}`,
      });
    }
  }

  if (deeds.status === "fulfilled" && deeds.value) {
    for (const deed of deeds.value.data) {
      results.push({
        id: deed.id,
        type: "deed",
        title: deed.deed_number ?? deed.title,
        subtitle: deed.deed_number ? deed.title : "—",
        href: `/ppat/deeds/${deed.id}`,
      });
    }
  }

  if (properties.status === "fulfilled" && properties.value) {
    for (const property of properties.value.data) {
      results.push({
        id: property.id,
        type: "property",
        title: property.certificate_number,
        subtitle: property.address,
        href: `/ppat/properties/${property.id}`,
      });
    }
  }

  if (matters.status === "fulfilled" && matters.value) {
    for (const matter of matters.value.data) {
      results.push({
        id: matter.id,
        type: "matter",
        title: matter.matter_number,
        subtitle: matter.title,
        href: `/ppat/matters/${matter.id}`,
      });
    }
  }

  return results.slice(0, 12);
}
