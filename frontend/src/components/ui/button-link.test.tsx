import { render, screen } from "@testing-library/react";

import { Button } from "@/components/ui/button";
import { ButtonLink } from "@/components/ui/button-link";

/**
 * The navigation control that carries button styling.
 *
 * It exists because `<Button render={<Link />}>` warned in development on every
 * such control across 23 files, and because the escape hatch that warning names,
 * `nativeButton={false}`, would have put `role="button"` on an anchor —
 * announcing a control that navigates as one that acts on the current page.
 *
 * These tests pin both halves of that decision: the element stays a link, and
 * its styling still comes from `buttonVariants` rather than being reinvented.
 *
 * Class strings are compared against `Button` rather than asserted literally, so
 * the design stays free to change without breaking this file — the same rule
 * `button.test.tsx` follows.
 */
describe("ButtonLink", () => {
  it("renders an anchor that keeps its destination", () => {
    render(<ButtonLink href="/projects/new">Proyek Baru</ButtonLink>);

    const link = screen.getByRole("link", { name: "Proyek Baru" });

    expect(link).toHaveAttribute("href", "/projects/new");
    expect(link.tagName).toBe("A");
  });

  it("is never announced as a button", () => {
    // The regression this component exists to prevent. A control that navigates
    // must keep the link role, so assistive technology says where it goes rather
    // than implying it acts on the page in front of the reader.
    render(<ButtonLink href="/projects/new">Proyek Baru</ButtonLink>);

    expect(screen.queryByRole("button")).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Proyek Baru" })).not.toHaveAttribute("role");
  });

  it("takes its styling from the same variants as Button", () => {
    // Not a class-string assertion: it pins that both components read one source
    // of styling, which is the reason `buttonVariants` is exported at all.
    const { unmount } = render(
      <ButtonLink href="/parties/companies/new" variant="outline" size="sm">
        Ubah
      </ButtonLink>,
    );
    const linkClasses = screen.getByRole("link", { name: "Ubah" }).className;
    unmount();

    render(
      <Button variant="outline" size="sm">
        Ubah
      </Button>,
    );

    expect(linkClasses).toBe(screen.getByRole("button", { name: "Ubah" }).className);
  });

  it("merges a caller's own classes", () => {
    // Every list header passes `gap-2`; if the slot dropped it, the icon and the
    // label would collide.
    render(
      <ButtonLink href="/tasks/new" className="gap-2">
        Tugas Baru
      </ButtonLink>,
    );

    expect(screen.getByRole("link", { name: "Tugas Baru" })).toHaveClass("gap-2");
  });

  it("keeps an accessible name given only as an aria-label", () => {
    // The Roles table builds its per-row control this way (CLAUDE.md section 49).
    render(
      <ButtonLink href="/settings/roles/01H" aria-label="Izin untuk Notaris">
        <span aria-hidden="true">x</span>
      </ButtonLink>,
    );

    expect(screen.getByRole("link", { name: "Izin untuk Notaris" })).toBeInTheDocument();
  });
});
