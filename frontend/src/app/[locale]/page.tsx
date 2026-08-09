import { redirect } from "@/i18n/navigation";
import { routing } from "@/i18n/routing";

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

/**
 * The locale root sends people to the dashboard, which then decides whether
 * they are signed in.
 *
 *   anonymous     /  ->  /id  ->  /id/dashboard  ->  /id/login
 *   authenticated /  ->  /id  ->  /id/dashboard
 *
 * The deterministic `/ -> /id` behaviour from D-020 is unchanged.
 */
export default async function LocaleRootPage({ params }: PageProps<"/[locale]">) {
  const { locale } = await params;

  redirect({ href: "/dashboard", locale });
}
