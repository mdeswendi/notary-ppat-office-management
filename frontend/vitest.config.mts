import react from "@vitejs/plugin-react";
import { defineConfig } from "vitest/config";

/**
 * Frontend test runner (O-032).
 *
 * The frontend's quality gate was `format:check`, `lint`, `typecheck` and
 * `build` — four commands that prove the code compiles and none that prove it
 * behaves. O-032 named the cost precisely: `visibleNavigation`, `can` /
 * `canWithScope` and the duplicate-advisory gate were verified by typecheck and
 * by running the real API, never by an executed unit test.
 *
 * **Vitest rather than Jest**, because the project already builds through Vite's
 * ecosystem via Next.js and the config below is the whole of the setup.
 *
 * **The `@/*` alias is read from `tsconfig.json` rather than restated here.**
 * `resolve.tsconfigPaths` is Vite's own resolution of the same file the
 * application compiles against, so the two can never drift. A hand-written
 * `resolve.alias` map would be a second copy of the truth, and the first thing
 * to go stale when a path changes.
 *
 * *(`vite-tsconfig-paths` was installed for this and then removed: Vite now does
 * it natively and says so at startup. Keeping the plugin would have meant a
 * dependency earning nothing.)*
 *
 * **These tests do not replace the backend suite or the HTTP smokes.** The
 * backend is the security boundary (`CLAUDE.md` section 28), and every gate here
 * is presentation. What this runner adds is the layer nothing else covered:
 * branch behaviour in components and pure helpers.
 */
export default defineConfig({
  plugins: [react()],

  resolve: {
    tsconfigPaths: true,
  },

  test: {
    environment: "jsdom",

    // `describe`, `it` and `expect` without an import in every file, matching
    // the Pest suite's shape on the backend. Typed through `src/vitest.d.ts`
    // rather than through a `types` array in `tsconfig.json`: setting that key
    // would *restrict* the auto-included `@types/*` packages and silently drop
    // `@types/node` and `@types/react`.
    globals: true,

    setupFiles: ["./vitest.setup.tsx"],

    // Colocated with what they test, the convention the rest of `src/` already
    // follows for features.
    include: ["src/**/*.test.{ts,tsx}"],

    /**
     * **Read the percentage carefully: it covers the files these tests import,
     * not the application.** `all` is deliberately left off, so a module no test
     * touches is absent from the report rather than counted as 0%.
     *
     * That is the useful number while the suite is a foundation — it answers
     * "how thoroughly is what we test, tested" — but it is *not* an
     * application-coverage figure and must never be quoted as one. **No
     * threshold is set**, because a threshold on a partial denominator would
     * fail the build for importing a new file rather than for testing less.
     */
    coverage: {
      provider: "v8",
      reporter: ["text", "json", "html"],
      exclude: [
        "node_modules/",
        ".next/",
        "coverage/",
        "vitest.config.mts",
        "vitest.setup.tsx",
        "src/vitest.d.ts",
        "**/*.test.{ts,tsx}",
        // Type-only modules compile to nothing, so they report as 0% covered
        // and would make the number meaningless.
        "src/types/**",
      ],
    },
  },
});
