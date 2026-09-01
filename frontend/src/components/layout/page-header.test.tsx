import { render, screen } from "@testing-library/react";

import { PageHeader } from "@/components/layout/page-header";

/**
 * The standard page header (docs/04_UI_DESIGN_SYSTEM.md section 12).
 *
 * These pin the branches a type cannot express: what appears when a slot is
 * filled, what is genuinely absent when it is not, and the one structural
 * promise the component makes — that a title with no action is not wrapped in a
 * flex row, because doing so changes how a long title wraps on all 56 pages
 * that pass no action today.
 *
 * Class strings are not asserted. Spacing is a design decision that should be
 * free to change without breaking a test, the rule button.test.tsx follows.
 */
describe("PageHeader", () => {
  it("renders the title as the page's level-one heading", () => {
    render(<PageHeader title="Proyek" />);

    expect(screen.getByRole("heading", { level: 1, name: "Proyek" })).toBeInTheDocument();
  });

  it("renders the description when given", () => {
    render(<PageHeader title="Proyek" description="Kelola proyek berjalan" />);

    expect(screen.getByText("Kelola proyek berjalan")).toBeInTheDocument();
  });

  it("renders no description paragraph when none is given", () => {
    // Absent, not empty: a stray paragraph would still take vertical space and
    // pull the content below it out of line with every other page.
    const { container } = render(<PageHeader title="Masuk" />);

    expect(container.querySelector("p")).toBeNull();
  });

  it("puts an action on the title's row", () => {
    render(<PageHeader title="Proyek" actions={<button type="button">Proyek Baru</button>} />);

    const heading = screen.getByRole("heading", { level: 1, name: "Proyek" });
    const action = screen.getByRole("button", { name: "Proyek Baru" });

    // Same row means a shared parent, which is what section 12's layout asks
    // for — the action beside the title, not beneath it.
    expect(heading.parentElement).toBe(action.parentElement?.parentElement);
  });

  it("leaves the title unwrapped when there is no action", () => {
    // The documented reason the row is conditional. As a flex item a lone <h1>
    // shrink-wraps its text instead of filling the column, so a long title
    // would break differently than it does today.
    const { container } = render(<PageHeader title="Proyek" />);

    const heading = screen.getByRole("heading", { level: 1, name: "Proyek" });

    expect(heading.parentElement).toBe(container.querySelector("header"));
  });

  it("renders a breadcrumb above the title when given", () => {
    render(<PageHeader title="Warkah" breadcrumb={<nav aria-label="Breadcrumb">PPAT</nav>} />);

    expect(screen.getByRole("navigation", { name: "Breadcrumb" })).toBeInTheDocument();
  });
});
