import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { vi } from "vitest";

import { Button } from "@/components/ui/button";

/**
 * The shared primitive every screen builds on (O-032).
 *
 * Deliberately narrow: this pins the contract other components rely on —
 * disabled means unclickable, `render` swaps the element without losing its
 * styling, and an icon-only button still has an accessible name. It does not
 * assert class strings, which are a design decision that should be free to
 * change without breaking a test.
 */
describe("Button", () => {
  it("renders its label and responds to a click", async () => {
    const onClick = vi.fn();
    const user = userEvent.setup();

    render(<Button onClick={onClick}>Simpan</Button>);

    await user.click(screen.getByRole("button", { name: "Simpan" }));

    expect(onClick).toHaveBeenCalledOnce();
  });

  it("does not fire while disabled", async () => {
    // The property every pending-mutation button in the application depends on:
    // `disabled={mutation.isPending}` must actually prevent a second submit.
    const onClick = vi.fn();
    const user = userEvent.setup();

    render(
      <Button disabled onClick={onClick}>
        Menyimpan
      </Button>,
    );

    await user.click(screen.getByRole("button", { name: "Menyimpan" }));

    expect(onClick).not.toHaveBeenCalled();
  });

  it("keeps an accessible name when it carries only an icon", async () => {
    // CLAUDE.md section 49: buttons need meaningful labels. The remove controls
    // in the participation section are icon-only and rely on this.
    render(
      <Button aria-label="Hapus tautan">
        <span aria-hidden="true">x</span>
      </Button>,
    );

    expect(screen.getByRole("button", { name: "Hapus tautan" })).toBeInTheDocument();
  });

  it("renders as another element without losing the button role contract", () => {
    // `render={<Link />}` is how every "go to edit" control is built. If the
    // slot dropped its props, those links would lose their styling and their
    // accessible name.
    //
    // The href deliberately names no real page: `@next/next/no-html-link-for-pages`
    // exists to stop application code bypassing `Link`, and it is right to fire
    // on an app route. Pointing at a non-route keeps the rule enforced
    // everywhere rather than switching it off here — this test is about the
    // slot forwarding props, and the destination is incidental to that.
    render(<Button render={<a href="/example-destination">Buka</a>} />);

    const link = screen.getByRole("link", { name: "Buka" });

    expect(link).toHaveAttribute("href", "/example-destination");
  });

  it("submits a form by default and can opt out", async () => {
    // A dialog footer holds a submit and a cancel side by side; if cancel
    // defaulted to submitting, closing the dialog would save.
    const onSubmit = vi.fn((event: React.FormEvent) => event.preventDefault());
    const user = userEvent.setup();

    render(
      <form onSubmit={onSubmit}>
        <Button type="submit">Kirim</Button>
        <Button type="button">Batal</Button>
      </form>,
    );

    await user.click(screen.getByRole("button", { name: "Batal" }));
    expect(onSubmit).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "Kirim" }));
    expect(onSubmit).toHaveBeenCalledOnce();
  });
});
