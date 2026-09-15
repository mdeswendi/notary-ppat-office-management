import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, vi } from "vitest";

import { AppUpdateNotice } from "@/components/layout/app-update-notice";

afterEach(() => {
  vi.restoreAllMocks();
});

describe("AppUpdateNotice", () => {
  it("stays hidden while the deployed version matches the loaded application", async () => {
    const fetchMock = vi
      .spyOn(globalThis, "fetch")
      .mockResolvedValue(new Response(JSON.stringify({ version: "same-version" })));

    render(<AppUpdateNotice currentVersion="same-version" />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("offers a user-controlled reload when a newer deployment is available", async () => {
    const events = userEvent.setup();
    const onReload = vi.fn();

    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      new Response(JSON.stringify({ version: "new-version" })),
    );

    render(<AppUpdateNotice currentVersion="old-version" onReload={onReload} />);

    const reload = await screen.findByRole("button", { name: "appUpdate.reload" });
    expect(screen.getByText("appUpdate.title")).toBeInTheDocument();

    await events.click(reload);
    expect(onReload).toHaveBeenCalledOnce();
  });

  it("ignores a failed background version check", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockRejectedValue(new Error("offline"));

    render(<AppUpdateNotice currentVersion="old-version" />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });
});
