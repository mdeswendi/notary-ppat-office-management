import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, vi } from "vitest";

import { PageBackLink } from "@/components/layout/page-back-link";

describe("PageBackLink", () => {
  afterEach(() => {
    vi.restoreAllMocks();
    Object.defineProperty(document, "referrer", { configurable: true, value: "" });
  });

  it("renders a locale-aware link to the supplied parent route", () => {
    render(<PageBackLink href="/projects" />);

    expect(screen.getByRole("link", { name: "common.back" })).toHaveAttribute("href", "/projects");
  });

  it("keeps the arrow decorative", () => {
    render(<PageBackLink href="/documents" />);

    expect(screen.getByRole("link", { name: "common.back" }).querySelector("svg")).toHaveAttribute(
      "aria-hidden",
      "true",
    );
  });

  it("returns to the actual previous page when it belongs to this application", () => {
    Object.defineProperty(document, "referrer", {
      configurable: true,
      value: `${window.location.origin}/projects/active`,
    });
    const back = vi.spyOn(window.history, "back").mockImplementation(() => undefined);

    render(<PageBackLink href="/projects" />);
    fireEvent.click(screen.getByRole("link", { name: "common.back" }));

    expect(back).toHaveBeenCalledOnce();
  });

  it("keeps the safe parent route when the page was opened from outside the application", () => {
    Object.defineProperty(document, "referrer", {
      configurable: true,
      value: "https://example.com/search",
    });
    render(<PageBackLink href="/documents" />);
    const link = screen.getByRole("link", { name: "common.back" });

    expect(link).toHaveAttribute("href", "/documents");
  });
});
