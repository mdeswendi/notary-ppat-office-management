import type { ReactElement, ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, type RenderOptions, type RenderResult } from "@testing-library/react";

/**
 * Render a component with the providers the application gives it (O-032).
 *
 * Only TanStack Query is wrapped here. next-intl and the locale-aware navigation
 * helpers are mocked in `vitest.setup.tsx` instead, because they need a Next.js
 * request context that no provider can supply outside a running server.
 *
 * **Retries are off and logging is silent.** A component under test that errors
 * should fail immediately with the error, not after three retries and a delay —
 * and a deliberately-failing request should not print a stack that reads like a
 * real fault.
 *
 * A **fresh `QueryClient` per render**, never a module-level one: a shared cache
 * would leak one test's data into the next and make failures depend on file
 * order.
 */
export function renderWithProviders(
  ui: ReactElement,
  options?: Omit<RenderOptions, "wrapper">,
): RenderResult & { queryClient: QueryClient } {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0, staleTime: 0 },
      mutations: { retry: false },
    },
  });

  function Providers({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
  }

  return { ...render(ui, { wrapper: Providers, ...options }), queryClient };
}
