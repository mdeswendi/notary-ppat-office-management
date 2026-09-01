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

  // A "renders as another element" test lived here, building a link the way the
  // application once did: `<Button render={<a href>} />`. It was removed rather
  // than kept, because Base UI's Button assumes a real `<button>` — its
  // `nativeButton` prop defaults to true — so that call emitted a console error
  // on every run and pinned a pattern the application has since stopped using.
  // Links that look like buttons are `ButtonLink` now, and what this test was
  // protecting — the styling and the accessible name surviving the swap — is
  // covered by `button-link.test.tsx`.

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
