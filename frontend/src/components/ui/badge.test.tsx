import { render, screen } from "@testing-library/react";

import { Badge } from "@/components/ui/badge";

/**
 * The shared status chip.
 *
 * Twenty-seven badge components and thirteen inline ones drew this shell
 * themselves before. These tests pin what the callers depend on rather than the
 * class strings: an accessible label survives, a caller's own classes survive,
 * and the tones stay distinct from one another.
 *
 * The last of those is the one worth having. `primary` and `primarySubtle`
 * differ by one opacity step and encode work in flight versus work settled; if a
 * refactor ever collapsed them, every status surface would quietly lose that
 * distinction and nothing else would fail.
 */
describe("Badge", () => {
  it("renders its label", () => {
    render(<Badge>Sedang Diproses</Badge>);

    expect(screen.getByText("Sedang Diproses")).toBeInTheDocument();
  });

  it("keeps an aria-label naming the field as well as the value", () => {
    // Every status badge passes one: the text says "Selesai", the label says
    // "Status: Selesai", so a screen reader hears which field it belongs to.
    render(<Badge aria-label="Status: Selesai">Selesai</Badge>);

    expect(screen.getByLabelText("Status: Selesai")).toBeInTheDocument();
  });

  it("merges a caller's classes with its own", () => {
    // The billing rows need `shrink-0`, and a cancelled invoice adds
    // `line-through` on top of its tone.
    render(<Badge className="shrink-0 line-through">Dibatalkan</Badge>);

    const badge = screen.getByText("Dibatalkan");

    expect(badge).toHaveClass("shrink-0");
    expect(badge).toHaveClass("line-through");
    expect(badge).toHaveClass("rounded-full");
  });

  it("gives every tone a distinct appearance", () => {
    // Not an assertion about which classes: an assertion that the six tones have
    // not silently become fewer than six.
    const tones = [
      "muted",
      "neutral",
      "primary",
      "primarySubtle",
      "ppat",
      "ppatStrong",
      "primaryStrong",
      "destructive",
    ] as const;

    const classNames = tones.map((tone) => {
      const { container, unmount } = render(<Badge tone={tone}>x</Badge>);
      const className = (container.firstChild as HTMLElement).className;
      unmount();

      return className;
    });

    expect(new Set(classNames).size).toBe(tones.length);
  });

  it("only lays out as a row when it carries an icon", () => {
    // `inline-flex` shifts how the chip sits on the text baseline, so the
    // twenty-three text-only badges must not get it.
    const { container: plain, unmount } = render(<Badge>Selesai</Badge>);
    expect((plain.firstChild as HTMLElement).className).not.toContain("inline-flex");
    unmount();

    const { container: withIcon } = render(
      <Badge withIcon>
        <span aria-hidden="true">!</span>
        Terlambat
      </Badge>,
    );
    expect((withIcon.firstChild as HTMLElement).className).toContain("inline-flex");
  });
});
