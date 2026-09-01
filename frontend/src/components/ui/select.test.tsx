import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { vi } from "vitest";

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

    const select = screen.getByRole("combobox", { name: "Status" });

    expect(select).toHaveClass("w-full");
    expect(select).toHaveClass("rounded-md");
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
