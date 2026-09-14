import type { SVGProps } from "react";

/** The approved PPAT house-and-leaf mark, drawn as one reusable vector. */
export function OfficeBrandMark(props: SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 72 72" fill="none" xmlns="http://www.w3.org/2000/svg" {...props}>
      <path
        d="M24 36V22.5L38 10l14 12.5V51H40"
        stroke="currentColor"
        strokeWidth="3.6"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <path
        d="M13 58.5c2.2-15.2 12.2-24.6 29.6-27.1-1.8 15.5-12 24.8-29.6 27.1Z"
        stroke="currentColor"
        strokeWidth="3.6"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <path
        d="M11 63c8.2-11.4 17.6-20.2 29.8-28.7"
        stroke="currentColor"
        strokeWidth="3.6"
        strokeLinecap="round"
      />
    </svg>
  );
}
