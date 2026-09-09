import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { vi } from "vitest";

import { ConfirmDialog } from "@/components/feedback/confirm-dialog";
import { Button } from "@/components/ui/button";

describe("ConfirmDialog", () => {
  it("requires confirmation before running an action", async () => {
    const onConfirm = vi.fn();
    const user = userEvent.setup();

    render(
      <ConfirmDialog
        trigger={<Button>Hapus</Button>}
        title="Hapus dokumen"
        description="Tindakan ini tidak dapat dibatalkan."
        cancelLabel="Batal"
        confirmLabel="Hapus"
        onConfirm={onConfirm}
      />,
    );

    await user.click(screen.getByRole("button", { name: "Hapus" }));

    expect(onConfirm).not.toHaveBeenCalled();
    expect(screen.getByRole("alertdialog", { name: "Hapus dokumen" })).toBeInTheDocument();

    const deleteButtons = screen.getAllByRole("button", { name: "Hapus" });
    await user.click(deleteButtons.at(-1)!);

    expect(onConfirm).toHaveBeenCalledOnce();
  });

  it("closes without running the action when cancelled", async () => {
    const onConfirm = vi.fn();
    const user = userEvent.setup();

    render(
      <ConfirmDialog
        trigger={<Button>Arsipkan</Button>}
        title="Arsipkan properti"
        description="Properti akan menjadi baca-saja."
        cancelLabel="Batal"
        confirmLabel="Arsipkan"
        onConfirm={onConfirm}
      />,
    );

    await user.click(screen.getByRole("button", { name: "Arsipkan" }));
    await user.click(screen.getByRole("button", { name: "Batal" }));

    expect(onConfirm).not.toHaveBeenCalled();
    expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument();
  });
});
