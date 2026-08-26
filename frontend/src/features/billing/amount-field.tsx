"use client";

import { useTranslations } from "next-intl";

/**
 * A monetary figure, or a deliberate placeholder (M8.2, D-125).
 *
 * ## The value is absent, not hidden
 *
 * `billing.amount.view` is a separate capability, and the server **omits** every
 * monetary key when it is not held — the amount never reaches the browser at all.
 * This component therefore has nothing to conceal: it renders what arrived, or a
 * placeholder saying the figure is withheld.
 *
 * That distinction matters more than it looks. A component that received the
 * value and styled it invisible would be a disclosure with a CSS rule in front of
 * it, and one devtools panel reads it. The masking that counts happens in
 * `MasksBillingAmounts` on the server; this is only its presentation.
 *
 * ## Formatting
 *
 * The amount is a **string** from PostgreSQL `numeric`. It is grouped for
 * reading, never parsed into a float and back — a figure that arrives exact
 * should stay exact all the way to the screen.
 *
 * `tabular-nums` so a column of figures lines up rather than shimmying, and the
 * currency is shown beside it because nothing in this application converts
 * between currencies.
 */
export function AmountField({
  amount,
  currency,
  visible,
  className = "",
  emphasis = false,
}: {
  amount: string | undefined;
  currency: string;
  visible: boolean;
  className?: string;
  emphasis?: boolean;
}) {
  const t = useTranslations("billing");

  if (!visible || amount === undefined) {
    return (
      <span
        className={`text-muted-foreground text-sm ${className}`}
        title={t("amountWithheldHint")}
      >
        {t("amountWithheld")}
      </span>
    );
  }

  return (
    <span className={`tabular-nums ${emphasis ? "font-medium" : ""} ${className}`}>
      <span className="text-muted-foreground mr-1 text-xs">{currency}</span>
      {group(amount)}
    </span>
  );
}

/**
 * Group the integer part for reading, leaving the decimals untouched.
 *
 * String work throughout: `Number(...)` on a monetary string is the one thing an
 * exact column exists to prevent.
 */
function group(amount: string): string {
  const [whole, fraction] = amount.split(".");

  const sign = whole.startsWith("-") ? "-" : "";
  const digits = sign ? whole.slice(1) : whole;

  const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ".");

  // Indonesian convention: `.` groups thousands and `,` separates decimals.
  return fraction === undefined ? `${sign}${grouped}` : `${sign}${grouped},${fraction}`;
}
