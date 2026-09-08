import { render, screen } from "@testing-library/react";

import { DateText } from "@/components/i18n/date-text";

describe("DateText", () => {
  it("keeps the API calendar date stable at UTC midnight", () => {
    render(<DateText value="2026-09-07T23:30:00-07:00" />);

    const date = screen.getByText("2026-09-07T00:00:00.000Z");

    expect(date).toHaveAttribute("datetime", "2026-09-07");
  });

  it("renders a quiet fallback for a missing or invalid date", () => {
    const { rerender } = render(<DateText value={null} />);
    expect(screen.getByText("—")).toBeInTheDocument();

    rerender(<DateText value="not-a-date" fallback="Belum ditentukan" />);
    expect(screen.getByText("Belum ditentukan")).toBeInTheDocument();
  });
});
