import { AxiosError, AxiosHeaders } from "axios";
import { describe, expect, it } from "vitest";

import { hasFieldError, toTaskErrorKey } from "@/features/tasks/task-errors";

/**
 * A genuine `AxiosError`, because the mapper narrows with `instanceof`.
 *
 * A plain object carrying `isAxiosError` falls through to the generic branch, so
 * a fixture shaped by hand would silently test the wrong path.
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

describe("toTaskErrorKey", () => {
  it("maps each status a task endpoint can answer with", () => {
    expect(toTaskErrorKey(axiosError(403))).toBe("forbidden");
    expect(toTaskErrorKey(axiosError(404))).toBe("notFound");
    expect(toTaskErrorKey(axiosError(429))).toBe("tooManyAttempts");
    expect(toTaskErrorKey(axiosError(500))).toBe("server");
  });

  /**
   * The branch a type cannot express, and the reason this file exists.
   *
   * Both cases are 422. A field error — a title too long, an assignee in another
   * Office — tells somebody to check what they typed. A status refusal —
   * completing finished work, deleting something still in flight — has nothing
   * to do with their input, and one message for both would send them looking for
   * a typo that is not there.
   */
  it("splits 422 on whether a field was the problem", () => {
    expect(toTaskErrorKey(axiosError(422, { errors: { title: ["too long"] } }))).toBe("validation");
    expect(toTaskErrorKey(axiosError(422, { errors: { assigned_to: ["unreachable"] } }))).toBe(
      "validation",
    );

    // A status refusal carries no `errors` bag at all.
    expect(toTaskErrorKey(axiosError(422, {}))).toBe("conflict");
    expect(toTaskErrorKey(axiosError(422, { message: "not completable" }))).toBe("conflict");
    expect(toTaskErrorKey(axiosError(422, { errors: {} }))).toBe("conflict");
  });

  it("reports a dropped connection rather than a server fault", () => {
    const headers = new AxiosHeaders();
    const offline = new AxiosError("Network Error", "ERR_NETWORK", { headers }, null, undefined);

    expect(toTaskErrorKey(offline)).toBe("network");
  });

  /**
   * No raw server text reaches a user through this path (`CLAUDE.md` §48).
   */
  it("ignores anything that is not an AxiosError", () => {
    expect(toTaskErrorKey(new Error("SQLSTATE[23514]: check_violation"))).toBe("server");
    expect(toTaskErrorKey({ isAxiosError: true, response: { status: 403 } })).toBe("server");
    expect(toTaskErrorKey(undefined)).toBe("server");
  });
});

describe("hasFieldError", () => {
  it("finds a field only in a 422 from Axios", () => {
    const validation = axiosError(422, { errors: { assigned_to: ["unreachable"] } });

    expect(hasFieldError(validation, "assigned_to")).toBe(true);
    expect(hasFieldError(validation, "project_id")).toBe(false);
    expect(hasFieldError(axiosError(403, { errors: { assigned_to: ["x"] } }), "assigned_to")).toBe(
      false,
    );
    expect(hasFieldError(new Error("nope"), "assigned_to")).toBe(false);
  });
});
