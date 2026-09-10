import { afterEach, describe, expect, it, vi } from "vitest";

import { publicOfficeBrand } from "@/config/public-brand";

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("publicOfficeBrand", () => {
  it("uses the deployment's public office name", () => {
    vi.stubEnv(
      "NEXT_PUBLIC_OFFICE_BRAND_NAME",
      "Kantor Notaris & PPAT Mila Widyahastuti, S.H., M.Kn",
    );

    expect(publicOfficeBrand("Kantor Notaris & PPAT")).toBe(
      "Kantor Notaris & PPAT Mila Widyahastuti, S.H., M.Kn",
    );
  });

  it.each(["", "   "])("uses the translated fallback for the configured value %j", (configured) => {
    vi.stubEnv("NEXT_PUBLIC_OFFICE_BRAND_NAME", configured);

    expect(publicOfficeBrand("Kantor Notaris & PPAT")).toBe("Kantor Notaris & PPAT");
  });

  it("uses the translated fallback when the variable is absent", () => {
    expect(publicOfficeBrand("Kantor Notaris & PPAT")).toBe("Kantor Notaris & PPAT");
  });
});
