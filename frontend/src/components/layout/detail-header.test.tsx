import { render, screen } from "@testing-library/react";

import { DetailHeader } from "@/components/layout/detail-header";

describe("DetailHeader", () => {
  it("renders the record identity with a level-one heading", () => {
    render(
      <DetailHeader
        reference="PRJ-2026-000001"
        title="Pekerjaan Administratif Demo 1"
        description="Kantor Pusat"
      />,
    );

    expect(
      screen.getByRole("heading", { level: 1, name: "Pekerjaan Administratif Demo 1" }),
    ).toBeInTheDocument();
    expect(screen.getByText("PRJ-2026-000001")).toBeInTheDocument();
    expect(screen.getByText("Kantor Pusat")).toBeInTheDocument();
  });

  it("renders badges, actions, and supporting feedback when supplied", () => {
    render(
      <DetailHeader
        title="Dokumen"
        badges={<span>Terbuka</span>}
        actions={<button type="button">Ubah</button>}
      >
        <p role="alert">Tindakan gagal</p>
      </DetailHeader>,
    );

    expect(screen.getByText("Terbuka")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Ubah" })).toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent("Tindakan gagal");
  });

  it("does not add empty optional content", () => {
    const { container } = render(<DetailHeader title="Properti" />);

    expect(container.querySelector("p")).toBeNull();
    expect(container.querySelector("button")).toBeNull();
  });
});
