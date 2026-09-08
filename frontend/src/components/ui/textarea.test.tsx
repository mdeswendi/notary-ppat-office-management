import { render, screen } from "@testing-library/react";

import { Textarea } from "@/components/ui/textarea";

describe("Textarea", () => {
  it("forwards accessible attributes and caller sizing", () => {
    render(
      <Textarea
        aria-label="Catatan"
        aria-invalid="true"
        aria-describedby="notes-error"
        className="min-h-32"
      />,
    );

    const textarea = screen.getByRole("textbox", { name: "Catatan" });

    expect(textarea).toHaveAttribute("aria-invalid", "true");
    expect(textarea).toHaveAttribute("aria-describedby", "notes-error");
    expect(textarea).toHaveClass("min-h-32");
    expect(textarea).toHaveAttribute("data-slot", "textarea");
  });
});
