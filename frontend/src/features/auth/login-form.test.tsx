import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import { LoginForm } from "@/features/auth/login-form";
import { renderWithProviders } from "@/test/render";

describe("LoginForm", () => {
  it("starts without sample credentials or a preselected remember option", () => {
    renderWithProviders(<LoginForm />);

    expect(screen.getByRole("textbox", { name: "auth.email" })).toHaveValue("");
    expect(screen.getByLabelText("auth.password")).toHaveValue("");

    const remember = screen.getByRole("checkbox", { name: "auth.rememberMe" });
    expect(remember).not.toBeChecked();
  });

  it("still reports missing credentials before a request is sent", async () => {
    const user = userEvent.setup();
    renderWithProviders(<LoginForm />);

    await user.click(screen.getByRole("button", { name: "auth.signIn" }));

    expect(await screen.findByText("validation.emailRequired")).toBeInTheDocument();
    expect(screen.getByText("validation.passwordRequired")).toBeInTheDocument();
  });
});
