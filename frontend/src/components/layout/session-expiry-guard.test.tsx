import { AxiosError, AxiosHeaders } from "axios";
import { act, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { SessionExpiryGuard } from "@/components/layout/session-expiry-guard";
import { renderWithProviders } from "@/test/render";
import { getCurrentUser, logout } from "@/services/auth";

const { replace, refresh } = vi.hoisted(() => ({
  replace: vi.fn(),
  refresh: vi.fn(),
}));

vi.mock("@/i18n/navigation", () => ({
  useRouter: () => ({ replace, refresh }),
}));

vi.mock("@/services/auth", () => ({
  getCurrentUser: vi.fn(),
  logout: vi.fn(),
}));

describe("SessionExpiryGuard", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-09-25T00:00:00Z"));
    vi.stubEnv("NEXT_PUBLIC_SESSION_IDLE_TIMEOUT_MINUTES", "120");
    vi.mocked(getCurrentUser).mockResolvedValue({
      id: "01USER00000000000000000000",
      name: "Pengguna Uji",
      email: "user@example.test",
      preferred_locale: "id",
      office: null,
      roles: [],
      permissions: [],
      permission_scopes: {},
    });
    vi.mocked(logout).mockResolvedValue(undefined);
    replace.mockClear();
    refresh.mockClear();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllEnvs();
  });

  it("clears the cached session and returns to login after two hours idle", () => {
    const { queryClient } = renderWithProviders(<SessionExpiryGuard />);
    queryClient.setQueryData(["private-data"], { secret: "cached" });

    act(() => {
      vi.advanceTimersByTime(120 * 60 * 1000);
    });

    expect(queryClient.getQueryData(["private-data"])).toBeUndefined();
    expect(replace).toHaveBeenCalledWith("/login");
    expect(refresh).toHaveBeenCalledOnce();
    expect(logout).toHaveBeenCalledOnce();
  });

  it("restarts the idle window after user activity", () => {
    renderWithProviders(<SessionExpiryGuard />);

    act(() => {
      vi.advanceTimersByTime(60 * 60 * 1000);
      window.dispatchEvent(new Event("pointerdown"));
      vi.advanceTimersByTime(119 * 60 * 1000);
    });

    expect(replace).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(60 * 1000);
    });

    expect(replace).toHaveBeenCalledWith("/login");
  });

  it("rechecks the server session when returning to the app", async () => {
    vi.useRealTimers();
    renderWithProviders(<SessionExpiryGuard />);

    act(() => {
      window.dispatchEvent(new Event("focus"));
    });

    await waitFor(() => expect(getCurrentUser).toHaveBeenCalledOnce());
    expect(replace).not.toHaveBeenCalled();
  });

  it("redirects to login when the server says the session expired", async () => {
    vi.useRealTimers();
    vi.mocked(getCurrentUser).mockRejectedValue(
      new AxiosError("Unauthenticated", undefined, undefined, undefined, {
        config: { headers: new AxiosHeaders() },
        data: null,
        headers: new AxiosHeaders(),
        status: 401,
        statusText: "Unauthorized",
      }),
    );
    const { queryClient } = renderWithProviders(<SessionExpiryGuard />);
    queryClient.setQueryData(["private-data"], { secret: "cached" });

    act(() => {
      window.dispatchEvent(new Event("focus"));
    });

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
    expect(queryClient.getQueryData(["private-data"])).toBeUndefined();
    expect(refresh).toHaveBeenCalledOnce();
  });
});
