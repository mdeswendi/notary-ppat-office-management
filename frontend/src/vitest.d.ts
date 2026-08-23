/// <reference types="vitest/globals" />
/// <reference types="@testing-library/jest-dom" />

/**
 * Ambient types for the test runner (O-032).
 *
 * A triple-slash reference rather than a `types` array in `tsconfig.json`.
 * Setting that key would switch TypeScript from "include every `@types/*`
 * package" to "include exactly these", silently dropping `@types/node` and
 * `@types/react` and breaking `pnpm typecheck` for the whole application — a
 * fix for the test files that would break the app they test.
 */

export {};
