import { describe, expect, it } from "vitest";

import { resolveActiveNavigationHref } from "@/components/layout/sidebar-nav";
import { navigationItems } from "@/config/navigation";

describe("resolveActiveNavigationHref", () => {
  it("activates Individuals without also activating the broader Directory route", () => {
    expect(resolveActiveNavigationHref("/parties/individuals", navigationItems)).toBe(
      "/parties/individuals",
    );
    expect(resolveActiveNavigationHref("/parties/individuals/01IND/edit", navigationItems)).toBe(
      "/parties/individuals",
    );
  });

  it("keeps Directory active on its own route", () => {
    expect(resolveActiveNavigationHref("/parties", navigationItems)).toBe("/parties");
  });

  it("uses the most specific match for other overlapping navigation routes", () => {
    expect(resolveActiveNavigationHref("/tasks/my", navigationItems)).toBe("/tasks/my");
    expect(resolveActiveNavigationHref("/tasks/01TASK", navigationItems)).toBe("/tasks");
  });
});
