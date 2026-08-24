import { AxiosError, AxiosHeaders } from "axios";
import { describe, expect, it } from "vitest";

import { hasFieldError, toDocumentErrorKey } from "@/features/documents/document-errors";

/**
 * A genuine `AxiosError`, because the mapper narrows with `instanceof`.
 *
 * A plain object carrying `isAxiosError` falls through to the generic branch, so
 * a fixture shaped by hand would silently test the wrong path — the defect O-032
 * found in the Matter fixtures.
 */
function axiosError(status: number, data: unknown = {}): AxiosError {
  const headers = new AxiosHeaders();
  const config = { headers };

  return new AxiosError("Request failed", String(status), config, null, {
    status,
    statusText: "",
    data,
    headers,
    config,
  });
}

describe("toDocumentErrorKey", () => {
  it("maps each status a document endpoint can answer with", () => {
    expect(toDocumentErrorKey(axiosError(403))).toBe("forbidden");
    expect(toDocumentErrorKey(axiosError(404))).toBe("notFound");
    expect(toDocumentErrorKey(axiosError(413))).toBe("tooLarge");
    expect(toDocumentErrorKey(axiosError(429))).toBe("tooManyAttempts");
    expect(toDocumentErrorKey(axiosError(500))).toBe("server");
  });

  /**
   * The branch a type cannot express, and the reason this file exists.
   *
   * Both cases are 422. A rejected **file** means "check your file"; a status the
   * act is not available from means something entirely different, and one message
   * for both would tell somebody to check a file that was fine.
   */
  it("splits 422 on whether the file was the problem", () => {
    expect(toDocumentErrorKey(axiosError(422, { errors: { file: ["too big"] } }))).toBe(
      "validation",
    );

    expect(toDocumentErrorKey(axiosError(422, {}))).toBe("conflict");

    expect(
      toDocumentErrorKey(axiosError(422, { errors: { "related_to.party_id": ["unreachable"] } })),
    ).toBe("conflict");
  });

  it("reports a dropped connection rather than a server fault", () => {
    const headers = new AxiosHeaders();
    const offline = new AxiosError("Network Error", "ERR_NETWORK", { headers }, null, undefined);

    expect(toDocumentErrorKey(offline)).toBe("network");
  });

  /**
   * No raw server text reaches a user through this path (`CLAUDE.md` §48).
   */
  it("ignores anything that is not an AxiosError", () => {
    expect(toDocumentErrorKey(new Error("SQLSTATE[23503]: foreign key violation"))).toBe("server");
    expect(toDocumentErrorKey({ isAxiosError: true, response: { status: 403 } })).toBe("server");
    expect(toDocumentErrorKey(undefined)).toBe("server");
  });
});

describe("hasFieldError", () => {
  it("finds a field only in a 422 from Axios", () => {
    const validation = axiosError(422, { errors: { file: ["bad type"] } });

    expect(hasFieldError(validation, "file")).toBe(true);
    expect(hasFieldError(validation, "title")).toBe(false);
    expect(hasFieldError(axiosError(403, { errors: { file: ["x"] } }), "file")).toBe(false);
    expect(hasFieldError(new Error("nope"), "file")).toBe(false);
  });
});
