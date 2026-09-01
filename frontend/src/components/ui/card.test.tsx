import { render, screen } from "@testing-library/react";

import { Card, CardHeader } from "@/components/ui/card";

/**
 * The shared section shell.
 *
 * It replaced three spellings of the same block — `SecuritySection`,
 * `DashboardPanel`, and twenty hand-written copies — so these tests pin the
 * things those three each needed, and the branch that keeps the Dashboard's
 * header row looking as it did.
 *
 * Class strings are not asserted; spacing is a design decision that should stay
 * free to change. The exceptions are `id` and a passed `className`, which are
 * contracts a caller relies on: the Security and Profile pages both anchor a
 * scroll jump on them.
 */
describe("Card", () => {
  it("renders its children in a section", () => {
    const { container } = render(
      <Card>
        <p>Isi kartu</p>
      </Card>,
    );

    const section = container.querySelector("section");

    expect(section).not.toBeNull();
    expect(screen.getByText("Isi kartu").parentElement).toBe(section);
  });

  it("keeps an id and a caller's classes, which anchored jumps depend on", () => {
    // `/profile#preferences` and the account menu's links land here.
    const { container } = render(
      <Card id="preferences" className="scroll-mt-6">
        <p>Bahasa</p>
      </Card>,
    );

    const section = container.querySelector("section");

    expect(section).toHaveAttribute("id", "preferences");
    expect(section).toHaveClass("scroll-mt-6");
  });
});

describe("CardHeader", () => {
  it("renders the title as a level-two heading", () => {
    // Level two, not one: a Card is a section of a page whose h1 is the
    // PageHeader. Two h1s on a page would flatten the document outline.
    render(<CardHeader title="Ringkasan" description="Detail proyek" />);

    expect(screen.getByRole("heading", { level: 2, name: "Ringkasan" })).toBeInTheDocument();
    expect(screen.getByText("Detail proyek")).toBeInTheDocument();
  });

  it("renders no description paragraph when none is given", () => {
    // Several sections pass a title alone; an empty paragraph would still take
    // vertical space and push their content out of line with the rest.
    const { container } = render(<CardHeader title="Identitas" />);

    expect(container.querySelector("p")).toBeNull();
  });

  it("puts an action on the title's row", () => {
    render(<CardHeader title="Tugas Saya" action={<a href="/tasks/my">Lihat semua</a>} />);

    const heading = screen.getByRole("heading", { level: 2, name: "Tugas Saya" });
    const action = screen.getByRole("link", { name: "Lihat semua" });

    expect(heading.parentElement?.parentElement).toBe(action.parentElement);
  });

  it("returns the plain title block when there is no action", () => {
    // No row wrapper, so the markup stays what the twenty detail sections had.
    const { container } = render(<CardHeader title="Ringkasan" description="Detail" />);

    const heading = screen.getByRole("heading", { level: 2, name: "Ringkasan" });

    expect(heading.parentElement).toBe(container.firstChild);
  });
});
