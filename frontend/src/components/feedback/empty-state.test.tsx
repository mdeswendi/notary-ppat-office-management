import { render, screen } from "@testing-library/react";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { EmptyState } from "@/components/feedback/empty-state";

/**
 * "Nothing here yet", as distinct from "something went wrong".
 *
 * Seventeen empty lists used to render through `BaseErrorState`, so they carried
 * `role="alert"` and a destructive icon. The first two tests are the regression
 * guards for exactly that, and they are worth more than they look: an empty list
 * is the normal state of a new deployment, and nothing about it fails loudly if
 * it starts shouting again.
 */
describe("EmptyState", () => {
  it("renders its title and description", () => {
    render(<EmptyState title="Belum ada Proyek" description="Buat proyek pertama Anda." />);

    expect(screen.getByRole("heading", { name: "Belum ada Proyek" })).toBeInTheDocument();
    expect(screen.getByText("Buat proyek pertama Anda.")).toBeInTheDocument();
  });

  it("is not an alert", () => {
    // The regression this component exists to prevent. `role="alert"` interrupts
    // a screen reader to say something needs attention now; an empty list does
    // not, and a new office meets one on every page before it enters any data.
    render(<EmptyState title="Belum ada Proyek" description="Buat proyek pertama Anda." />);

    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("differs from BaseErrorState, which is still an alert", () => {
    // Pins the distinction rather than either implementation: if the two ever
    // collapse back into one component, this fails.
    const { unmount } = render(<EmptyState title="Kosong" description="Belum ada isi." />);
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    unmount();

    render(<BaseErrorState title="Gagal" description="Coba lagi." />);
    expect(screen.getByRole("alert")).toBeInTheDocument();
  });

  it("renders a way forward when one is given", () => {
    render(
      <EmptyState
        title="Belum ada Proyek"
        description="Buat proyek pertama Anda."
        action={<button type="button">Proyek Baru</button>}
      />,
    );

    expect(screen.getByRole("button", { name: "Proyek Baru" })).toBeInTheDocument();
  });

  it("renders no action wrapper when none is given", () => {
    render(<EmptyState title="Kosong" description="Belum ada isi." />);

    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });
});
