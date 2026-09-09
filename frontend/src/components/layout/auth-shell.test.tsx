import { screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { AuthShell } from "@/components/layout/auth-shell";
import { renderWithProviders } from "@/test/render";

describe("AuthShell", () => {
  it("gives an account flow one heading and a named main landmark", () => {
    renderWithProviders(
      <AuthShell officeLabel="Kantor Uji" title="Masuk" description="Gunakan akun Anda.">
        <form aria-label="Form masuk" />
      </AuthShell>,
    );

    expect(screen.getByRole("main")).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 1, name: "Masuk" })).toBeInTheDocument();
    expect(screen.getByText("Kantor Uji")).toBeInTheDocument();
    expect(screen.getByText("Gunakan akun Anda.")).toBeInTheDocument();
  });
});
