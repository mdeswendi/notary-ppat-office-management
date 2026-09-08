import { render, screen } from "@testing-library/react";

import { InlineAlert } from "@/components/feedback/inline-alert";

describe("InlineAlert", () => {
  it("announces errors assertively without relying on colour", () => {
    render(<InlineAlert>Perubahan tidak dapat disimpan.</InlineAlert>);

    expect(screen.getByRole("alert")).toHaveTextContent("Perubahan tidak dapat disimpan.");
    expect(screen.getByRole("alert").querySelector("svg")).toHaveAttribute("aria-hidden", "true");
  });

  it("announces informational feedback politely", () => {
    render(<InlineAlert tone="info">Data sedang diperbarui.</InlineAlert>);

    expect(screen.getByRole("status")).toHaveTextContent("Data sedang diperbarui.");
  });

  it("keeps caller styling and accessibility overrides", () => {
    render(
      <InlineAlert tone="success" role="note" className="mt-4">
        Perubahan tersimpan.
      </InlineAlert>,
    );

    expect(screen.getByRole("note")).toHaveClass("mt-4");
  });
});
