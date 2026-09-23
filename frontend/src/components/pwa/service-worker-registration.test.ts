import { afterEach, describe, expect, it, vi } from "vitest";

import { registerServiceWorker } from "@/components/pwa/service-worker-registration";

const originalServiceWorker = Object.getOwnPropertyDescriptor(navigator, "serviceWorker");

afterEach(() => {
  if (originalServiceWorker) {
    Object.defineProperty(navigator, "serviceWorker", originalServiceWorker);
  } else {
    Reflect.deleteProperty(navigator, "serviceWorker");
  }
});

describe("registerServiceWorker", () => {
  it("registers the root service worker and checks for an update", async () => {
    const update = vi.fn().mockResolvedValue(undefined);
    const register = vi.fn().mockResolvedValue({ update });

    Object.defineProperty(navigator, "serviceWorker", {
      configurable: true,
      value: { register },
    });

    await registerServiceWorker();

    expect(register).toHaveBeenCalledWith("/sw.js", { scope: "/" });
    expect(update).toHaveBeenCalledOnce();
  });

  it("does nothing when the browser does not support service workers", async () => {
    Reflect.deleteProperty(navigator, "serviceWorker");

    await expect(registerServiceWorker()).resolves.toBeUndefined();
  });
});
