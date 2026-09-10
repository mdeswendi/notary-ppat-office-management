import { afterEach, describe, expect, it, vi } from "vitest";

import manifest from "@/app/manifest";

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("web app manifest", () => {
  it("uses the configured public office identity", () => {
    vi.stubEnv(
      "NEXT_PUBLIC_OFFICE_BRAND_NAME",
      "Kantor Notaris & PPAT Mila Widyahastuti, S.H., M.Kn",
    );

    expect(manifest().name).toBe("Kantor Notaris & PPAT Mila Widyahastuti, S.H., M.Kn");
  });

  it("opens the Indonesian dashboard as a standalone application", () => {
    expect(manifest()).toMatchObject({
      id: "/id/",
      short_name: "Notaris & PPAT",
      start_url: "/id/dashboard",
      scope: "/",
      display: "standalone",
      theme_color: "#172554",
    });
  });

  it("provides standard and maskable Android icons", () => {
    expect(manifest().icons).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ sizes: "192x192", purpose: "any" }),
        expect.objectContaining({ sizes: "512x512", purpose: "any" }),
        expect.objectContaining({ sizes: "192x192", purpose: "maskable" }),
        expect.objectContaining({ sizes: "512x512", purpose: "maskable" }),
      ]),
    );
  });
});
