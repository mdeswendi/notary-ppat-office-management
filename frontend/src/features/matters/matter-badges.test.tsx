import { render, screen } from "@testing-library/react";

import {
  MatterDomainBadge,
  MatterPriorityBadge,
  MatterStatusBadge,
} from "@/features/matters/matter-badges";
import { MATTER_STATUSES } from "@/types/matter";

/**
 * The M4.4 badges (O-032).
 *
 * `t()` returns its key in tests, so these assert **which message a badge reached
 * for** rather than the Indonesian wording — the part that is a defect if wrong,
 * and the part translators must stay free to change.
 */
describe("MatterStatusBadge", () => {
  it("renders every canonical status, including the four nothing can set", () => {
    // `IN_PROGRESS`, `WAITING`, `ON_HOLD` and `ARCHIVED` are unreachable in M4 —
    // Matter has no `change_status` capability (D-109) — but they are canonical
    // vocabulary a filter can select on. A badge that could not draw one would
    // crash on data the backend is entitled to return.
    for (const status of MATTER_STATUSES) {
      const { unmount } = render(<MatterStatusBadge status={status} />);

      expect(screen.getByText(`matters.statuses.${status}`)).toBeInTheDocument();

      unmount();
    }
  });

  it("does not rely on colour alone to convey the status", () => {
    // CLAUDE.md section 49. The text carries the meaning; the tint is decoration,
    // and the aria-label names the field as well as the value.
    render(<MatterStatusBadge status="COMPLETED" />);

    expect(
      screen.getByLabelText("matters.statusLabel: matters.statuses.COMPLETED"),
    ).toBeInTheDocument();
  });

  it("renders a dash rather than an empty badge for a null status", () => {
    render(<MatterStatusBadge status={null} />);

    expect(screen.getByText("—")).toBeInTheDocument();
    expect(screen.queryByText(/matters\.statuses\./)).not.toBeInTheDocument();
  });
});

describe("MatterPriorityBadge", () => {
  it("renders a priority through its message key", () => {
    render(<MatterPriorityBadge priority="URGENT" />);

    expect(screen.getByText("matters.priorities.URGENT")).toBeInTheDocument();
  });

  it("renders a dash for no priority", () => {
    // Priority is genuinely optional on a Matter; an empty badge would read as a
    // rendering fault rather than as an unset field.
    render(<MatterPriorityBadge priority={null} />);

    expect(screen.getByText("—")).toBeInTheDocument();
  });
});

describe("MatterDomainBadge", () => {
  it("distinguishes the two domains by text, not only by accent", () => {
    const { unmount } = render(<MatterDomainBadge domain="NOTARY" />);
    expect(screen.getByText("matters.domains.NOTARY")).toBeInTheDocument();
    unmount();

    render(<MatterDomainBadge domain="PPAT" />);
    expect(screen.getByText("matters.domains.PPAT")).toBeInTheDocument();
  });

  it("names the field in its accessible label", () => {
    render(<MatterDomainBadge domain="PPAT" />);

    expect(screen.getByLabelText("matters.domainLabel: matters.domains.PPAT")).toBeInTheDocument();
  });
});
