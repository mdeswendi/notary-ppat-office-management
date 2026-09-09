import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import { PasswordInput } from "@/components/forms/password-input";

describe("PasswordInput", () => {
  it("reveals and hides the value without changing the field", async () => {
    const user = userEvent.setup();
    render(<PasswordInput aria-label="Kata sandi" showLabel="Tampilkan" hideLabel="Sembunyikan" />);

    const input = screen.getByLabelText("Kata sandi");

    expect(input).toHaveAttribute("type", "password");
    await user.click(screen.getByRole("button", { name: "Tampilkan" }));
    expect(input).toHaveAttribute("type", "text");
    await user.click(screen.getByRole("button", { name: "Sembunyikan" }));
    expect(input).toHaveAttribute("type", "password");
  });
});
