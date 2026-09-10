/**
 * Resolve the public office identity shown before authentication.
 *
 * The authenticated shell reads organization and office names from `/api/v1/me`.
 * Login and password-reset pages cannot do that because no account context exists
 * yet, so deployments may provide a public, non-secret name at build time. A
 * blank or whitespace-only value deliberately falls back to translated generic
 * copy instead of rendering an empty brand.
 */
export function publicOfficeBrand(fallback: string): string {
  return process.env.NEXT_PUBLIC_OFFICE_BRAND_NAME?.trim() || fallback;
}
