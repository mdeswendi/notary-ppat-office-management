import { render, screen } from "@testing-library/react";

import { PageContainer } from "@/components/layout/page-container";

describe("PageContainer", () => {
  it("gives application pages a subtle motion-safe entrance", () => {
    render(
      <PageContainer>
        <p>Page content</p>
      </PageContainer>,
    );

    const container = screen.getByText("Page content").parentElement;

    expect(container).toHaveClass(
      "animate-in",
      "fade-in",
      "slide-in-from-bottom-1",
      "duration-300",
      "motion-reduce:animate-none",
    );
  });

  it("preserves page-specific presentation classes", () => {
    render(
      <PageContainer className="gap-5">
        <p>Custom page</p>
      </PageContainer>,
    );

    expect(screen.getByText("Custom page").parentElement).toHaveClass("gap-5");
  });
});
