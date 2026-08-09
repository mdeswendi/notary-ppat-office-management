import axios from "axios";

/**
 * The single Axios instance for the whole application.
 *
 * Authentication is Laravel Sanctum's first-party SPA mode: the browser is
 * identified by an HttpOnly session cookie that JavaScript cannot read. There
 * is deliberately no request interceptor attaching an Authorization header and
 * no token read from localStorage or sessionStorage — see
 * docs/07_SECURITY_RULES.md section 3.
 *
 * `withXSRFToken` makes Axios echo the readable XSRF-TOKEN cookie back as the
 * X-XSRF-TOKEN header, which is how Laravel validates CSRF for the SPA. The
 * cookie value is never logged.
 */
export const apiClient = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000",
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: "application/json",
  },
});
