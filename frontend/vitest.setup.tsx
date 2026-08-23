import "@testing-library/jest-dom/vitest";

import type { ReactNode } from "react";
import { cleanup } from "@testing-library/react";
import { afterEach, vi } from "vitest";

/**
 * Test environment setup (O-032).
 *
 * **This file is `.tsx`, not `.ts`.** The navigation mock below returns JSX, and
 * a `.ts` file cannot contain it — TypeScript parses `<a>` as a type assertion
 * and fails on the closing tag.
 *
 * Everything mocked here is mocked because **jsdom or Next.js cannot provide
 * it**, never to make a test pass. Nothing application-specific is stubbed: a
 * component's own behaviour is what the tests are for.
 */

afterEach(() => {
  cleanup();
});

/**
 * The App Router hooks, which throw outside a Next.js request context.
 *
 * `next/image` is deliberately **not** mocked: the application imports it
 * nowhere, so a mock would be configuration describing something that does not
 * exist — the kind of dead setup nobody later dares delete.
 */
vi.mock("next/navigation", () => ({
  useRouter: () => ({
    push: vi.fn(),
    replace: vi.fn(),
    prefetch: vi.fn(),
    back: vi.fn(),
    forward: vi.fn(),
    refresh: vi.fn(),
  }),
  usePathname: () => "/",
  useSearchParams: () => new URLSearchParams(),
}));

/**
 * next-intl, which needs a provider and loaded messages.
 *
 * **`t()` returns the key**, which is deliberate and is what makes these tests
 * useful rather than brittle: a test asserting the Indonesian string would fail
 * the moment somebody improves the wording, and would quietly pass if a
 * component rendered the right sentence from the wrong key. Asserting on
 * `matters.statuses.OPEN` pins the thing that actually matters — that the
 * component reached for the correct message — and leaves the translators free.
 *
 * Message *parity and orphan* checks are a separate concern and stay where they
 * are, in the milestone verification scripts.
 */
vi.mock("next-intl", () => ({
  useTranslations: (namespace?: string) => {
    const translate = (key: string) => (namespace ? `${namespace}.${key}` : key);

    // next-intl exposes helpers on the returned function; components here use
    // only the call form, but `rich` and `raw` are provided so a component that
    // starts using them fails loudly rather than silently rendering undefined.
    translate.rich = (key: string) => (namespace ? `${namespace}.${key}` : key);
    translate.raw = (key: string) => (namespace ? `${namespace}.${key}` : key);
    translate.has = () => true;

    return translate;
  },
  useLocale: () => "id",
  useFormatter: () => ({
    dateTime: (value: Date) => value.toISOString(),
    number: (value: number) => String(value),
    relativeTime: () => "",
  }),
}));

/**
 * The locale-aware navigation helpers built by `createNavigation`.
 *
 * `Link` renders a plain anchor so tests can assert the destination — which is
 * the part worth pinning, since a wrong `href` is a real defect and the locale
 * segment is next-intl's job rather than the component's.
 */
vi.mock("@/i18n/navigation", () => ({
  Link: ({ children, href, ...rest }: { children: ReactNode; href: string }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
  useRouter: () => ({
    push: vi.fn(),
    replace: vi.fn(),
    prefetch: vi.fn(),
    back: vi.fn(),
  }),
  usePathname: () => "/",
  redirect: vi.fn(),
  getPathname: ({ href }: { href: string }) => href,
}));

/**
 * Browser APIs jsdom does not implement.
 *
 * Base UI — the primitive behind `dialog.tsx` and `sheet.tsx` — measures and
 * observes layout, so all three of these are load-bearing for any test that
 * opens a dialog. This is not Radix; the same shims happen to be needed.
 */
class MockPointerEvent extends Event {
  readonly button: number;
  readonly ctrlKey: boolean;
  readonly pointerType: string;

  constructor(type: string, props: PointerEventInit = {}) {
    super(type, props);
    this.button = props.button ?? 0;
    this.ctrlKey = props.ctrlKey ?? false;
    this.pointerType = props.pointerType ?? "mouse";
  }
}

window.PointerEvent = MockPointerEvent as unknown as typeof window.PointerEvent;

window.HTMLElement.prototype.scrollIntoView = vi.fn();
window.HTMLElement.prototype.releasePointerCapture = vi.fn();
window.HTMLElement.prototype.hasPointerCapture = vi.fn(() => false);

/**
 * Implicit form submission, which jsdom does not perform.
 *
 * In a browser, clicking a `type="submit"` button runs the button's *activation
 * behaviour*: it submits the form it belongs to. jsdom implements
 * `form.requestSubmit()` and dispatches `submit` correctly when it is called —
 * what it never does is call it from a click.
 *
 * Without this, **no form in the application can be submitted from a test**, and
 * the failure is silent rather than loud: the click lands, nothing happens, and
 * the assertion fails with "expected 1, got 0" as though the component were
 * broken.
 *
 * Diagnosed rather than guessed. Three probes narrowed it precisely:
 * `typeof form.requestSubmit` is `"function"`, calling it directly fires the
 * handler, and `fireEvent.submit` fires the handler — but a `userEvent.click` on
 * a submit button fires nothing. A bare `<button type="submit">` in a bare
 * `<form>` behaves identically to the shared `Button`, so this is nothing to do
 * with Base UI or with any component here.
 *
 * The listener implements only the missing step. It bubbles rather than
 * captures, so React's own handlers run first, and it honours
 * `defaultPrevented`, so a handler that cancels the click still cancels the
 * submission.
 */
document.addEventListener("click", (event) => {
  if (event.defaultPrevented) {
    return;
  }

  const target = event.target as HTMLElement | null;
  const submitter = target?.closest?.("button, input");

  if (!(submitter instanceof HTMLButtonElement) && !(submitter instanceof HTMLInputElement)) {
    return;
  }

  // A button with no explicit type defaults to `submit` in HTML, but Base UI
  // sets `type="button"` unless asked otherwise, so reading the attribute is
  // both correct and sufficient here.
  if (submitter.type !== "submit" || submitter.disabled) {
    return;
  }

  submitter.form?.requestSubmit(submitter);
});

globalThis.ResizeObserver = class ResizeObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
} as unknown as typeof ResizeObserver;

Object.defineProperty(window, "matchMedia", {
  writable: true,
  value: vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
});
