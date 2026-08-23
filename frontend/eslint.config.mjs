import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
    // Coverage output is generated, not authored.
    "coverage/**",
  ]),

  /**
   * Test files and the test environment setup (O-032).
   *
   * **One rule is relaxed, and only here.** `@next/next/no-html-link-for-pages`
   * exists to stop *application* code bypassing `Link` and losing client-side
   * navigation. It fires on any internal-looking `href`, which is right in a page
   * or a component and wrong in these two places:
   *
   *   - `vitest.setup.tsx` mocks the locale-aware `Link` **as** a plain anchor,
   *     which is the entire point of the mock — it lets a test assert the
   *     destination without a Next.js request context;
   *   - a test rendering `<Button render={<a href="…" />} />` is checking that
   *     the slot forwards props, not navigating anywhere.
   *
   * Narrowed to these paths rather than switched off globally, and stated rather
   * than scattered as inline disables, so the rule keeps protecting every real
   * page and component (`CLAUDE.md` section 52: no suppression without a
   * documented reason).
   */
  {
    files: ["src/**/*.test.{ts,tsx}", "src/test/**/*.{ts,tsx}", "vitest.setup.tsx"],
    rules: {
      "@next/next/no-html-link-for-pages": "off",
    },
  },
]);

export default eslintConfig;
