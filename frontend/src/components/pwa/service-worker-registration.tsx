"use client";

import { useEffect } from "react";

export async function registerServiceWorker() {
  if (!("serviceWorker" in navigator)) {
    return;
  }

  const registration = await navigator.serviceWorker.register("/sw.js", {
    scope: "/",
  });

  await registration.update();
}

export function ServiceWorkerRegistration() {
  useEffect(() => {
    const register = () => {
      // Installation must not make the application unusable when a browser
      // rejects service workers (for example, in a private browsing mode).
      void registerServiceWorker().catch(() => undefined);
    };

    if (document.readyState === "complete") {
      register();
      return;
    }

    window.addEventListener("load", register, { once: true });

    return () => window.removeEventListener("load", register);
  }, []);

  return null;
}
