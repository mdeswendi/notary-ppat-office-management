import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

const withNextIntl = createNextIntlPlugin();

const nextConfig: NextConfig = {
  env: {
    NEXT_PUBLIC_APP_VERSION:
      process.env.VERCEL_GIT_COMMIT_SHA ?? process.env.VERCEL_DEPLOYMENT_ID ?? "development",
  },
  async rewrites() {
    const upstream = process.env.API_INTERNAL_URL;

    if (!upstream) {
      return [];
    }

    return [
      {
        source: "/backend/:path*",
        destination: `${upstream.replace(/\/$/, "")}/:path*`,
      },
    ];
  },
};

export default withNextIntl(nextConfig);
