import type { MetadataRoute } from "next";

import { publicOfficeBrand } from "@/config/public-brand";

export default function manifest(): MetadataRoute.Manifest {
  return {
    id: "/id/",
    name: publicOfficeBrand("Kantor Notaris & PPAT"),
    short_name: "Notaris & PPAT",
    description: "Aplikasi manajemen pekerjaan Kantor Notaris dan PPAT.",
    start_url: "/id/dashboard",
    scope: "/",
    display: "standalone",
    background_color: "#f8fafc",
    theme_color: "#172554",
    lang: "id",
    categories: ["business", "productivity"],
    icons: [
      {
        src: "/icons/notary-app-192.png",
        sizes: "192x192",
        type: "image/png",
        purpose: "any",
      },
      {
        src: "/icons/notary-app-512.png",
        sizes: "512x512",
        type: "image/png",
        purpose: "any",
      },
      {
        src: "/icons/notary-app-maskable-192.png",
        sizes: "192x192",
        type: "image/png",
        purpose: "maskable",
      },
      {
        src: "/icons/notary-app-maskable-512.png",
        sizes: "512x512",
        type: "image/png",
        purpose: "maskable",
      },
    ],
  };
}
