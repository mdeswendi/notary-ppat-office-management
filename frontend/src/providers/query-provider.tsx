"use client";

import { useState, type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

/**
 * TanStack Query provider for server state.
 *
 * The client is created inside state so each browser session gets its own
 * instance and no cache is shared between requests during server rendering.
 *
 * `retry: false` for queries because the main consumer is `GET /api/v1/me`,
 * where a 401 is a real answer — "not signed in" — and retrying it only delays
 * the redirect to the login page.
 */
export function QueryProvider({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 30_000,
            retry: false,
            refetchOnWindowFocus: false,
          },
        },
      }),
  );

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}
