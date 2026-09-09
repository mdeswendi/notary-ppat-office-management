import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import { SkipLink } from "@/components/layout/skip-link";

describe("SkipLink", () => {
  it("is the first keyboard stop and points to the application content", async () => {
    const user = userEvent.setup();
    render(<SkipLink />);

    const link = screen.getByRole("link", { name: "common.skipToContent" });

    await user.tab();

    expect(link).toHaveFocus();
    expect(link).toHaveAttribute("href", "#main-content");
  });
});
