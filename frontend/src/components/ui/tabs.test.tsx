import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

describe("Tabs", () => {
  it("renders tablist with triggers and default content", () => {
    render(
      <Tabs defaultValue="tab1">
        <TabsList>
          <TabsTrigger value="tab1">Tab 1</TabsTrigger>
          <TabsTrigger value="tab2">Tab 2</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1">Content 1</TabsContent>
        <TabsContent value="tab2">Content 2</TabsContent>
      </Tabs>,
    );

    expect(screen.getByRole("tablist")).toBeInTheDocument();

    const tab1 = screen.getByRole("tab", { name: "Tab 1" });
    const tab2 = screen.getByRole("tab", { name: "Tab 2" });

    expect(tab1).toHaveAttribute("aria-selected", "true");
    expect(tab2).toHaveAttribute("aria-selected", "false");

    expect(screen.getByText("Content 1")).toBeInTheDocument();
    expect(screen.queryByText("Content 2")).not.toBeInTheDocument();
  });

  it("switches content when another tab trigger is clicked", async () => {
    const user = userEvent.setup();

    render(
      <Tabs defaultValue="overview">
        <TabsList>
          <TabsTrigger value="overview">Ringkasan</TabsTrigger>
          <TabsTrigger value="parties">Pihak</TabsTrigger>
        </TabsList>
        <TabsContent value="overview">Konten Ringkasan</TabsContent>
        <TabsContent value="parties">Konten Pihak</TabsContent>
      </Tabs>,
    );

    const partiesTab = screen.getByRole("tab", { name: "Pihak" });
    await user.click(partiesTab);

    expect(partiesTab).toHaveAttribute("aria-selected", "true");
    expect(screen.getByText("Konten Pihak")).toBeInTheDocument();
    expect(screen.queryByText("Konten Ringkasan")).not.toBeInTheDocument();
  });
});
