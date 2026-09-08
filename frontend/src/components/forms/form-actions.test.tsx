import { render, screen } from "@testing-library/react";

import { FormActions } from "@/components/forms/form-actions";
import { Button } from "@/components/ui/button";

describe("FormActions", () => {
  it("provides responsive action layout without changing button semantics", () => {
    render(
      <FormActions>
        <Button type="submit">Simpan</Button>
      </FormActions>,
    );

    expect(screen.getByRole("button", { name: "Simpan" })).toHaveAttribute("type", "submit");

    const actions = screen.getByRole("button", { name: "Simpan" }).parentElement;
    expect(actions).toHaveAttribute("data-slot", "form-actions");
    expect(actions).toHaveClass("flex-col");
    expect(actions).toHaveClass("sm:flex-row");
  });
});
