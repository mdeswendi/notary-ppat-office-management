import { render, screen } from "@testing-library/react";

import { PageBackLink } from "@/components/layout/page-back-link";

describe("PageBackLink", () => {
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
});
