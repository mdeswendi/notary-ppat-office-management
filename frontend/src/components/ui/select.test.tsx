import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { vi } from "vitest";

import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";

/**
 * The shared dropdown.
 *
 * Forty-six copies of one class string became this. The tests pin what the call
 * sites actually rely on — it is still a native `<select>`, it still forwards
 * every prop those call sites pass, and a caller's own classes survive.
 *
 * The `ref` test earns its place: three forms reach this through
 * `form.register()`, which passes a `ref`. React 19 hands `ref` to a function
 * component as an ordinary prop, so the spread carries it — but that is a
 * version-dependent behaviour, and if it ever stopped working those forms would
 * silently stop registering their field rather than fail loudly.
 */
describe("Select", () => {
  it("renders a native select with its options", () => {
    render(
      <Select aria-label="Status">
        <option value="">Semua</option>
        <option value="OPEN">Terbuka</option>
      </Select>,
    );

    const select = screen.getByRole("combobox", { name: "Status" });

    expect(select.tagName).toBe("SELECT");
    expect(screen.getByRole("option", { name: "Terbuka" })).toBeInTheDocument();
  });

  it("reports the chosen value to its caller", async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();

    render(
      <Select aria-label="Status" value="" onChange={onChange}>
        <option value="">Semua</option>
        <option value="OPEN">Terbuka</option>
      </Select>,
    );

    await user.selectOptions(screen.getByRole("combobox", { name: "Status" }), "OPEN");

    expect(onChange).toHaveBeenCalled();
  });

  it("forwards a ref, which react-hook-form depends on", () => {
    const ref = { current: null as HTMLSelectElement | null };

    render(
      <Select aria-label="Jenis" ref={ref}>
        <option value="a">A</option>
      </Select>,
    );

    expect(ref.current).toBe(screen.getByRole("combobox", { name: "Jenis" }));
  });

  it("keeps a caller's own classes", () => {
    render(
      <Select aria-label="Status" className="w-full">
        <option value="a">A</option>
      </Select>,
    );

    expect(screen.getByRole("combobox", { name: "Status" })).toHaveClass("w-full");
  });

  it("is sized exactly like Input, which is what keeps a filter row aligned", () => {
    // The defect this fixed: a 36px dropdown standing beside a 32px search box
    // in every filter row, on every list in the product.
    //
    // The two are compared rather than measured against a named class, so the
    // check survives someone rescaling both controls and fails only if they
    // drift apart again — which is the thing that actually goes wrong.
    const sizing = (className: string) =>
      className
        .split(/\s+/)
        .filter((token) => /^h-|^rounded-/.test(token))
        .sort();

    const { unmount } = render(
      <Select aria-label="Jenis">
        <option value="a">A</option>
      </Select>,
    );
    const select = sizing(screen.getByRole("combobox", { name: "Jenis" }).className);
    unmount();

    render(<Input aria-label="Nama" />);
    const input = sizing(screen.getByRole("textbox", { name: "Nama" }).className);

    expect(select).toEqual(input);
  });

  it("passes through the attributes forms set on it", () => {
    // `aria-invalid` and `disabled` are both used at real call sites, and both
    // would be silently dropped by a component that forgot to spread.
    render(
      <Select aria-label="Jenis" aria-invalid disabled>
        <option value="a">A</option>
      </Select>,
    );

    const select = screen.getByRole("combobox", { name: "Jenis" });

    expect(select).toBeDisabled();
    expect(select).toHaveAttribute("aria-invalid", "true");
  });
});
