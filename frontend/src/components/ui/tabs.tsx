"use client";

import { Tabs as TabsPrimitive } from "@base-ui/react/tabs";

import { cn } from "@/lib/utils";

/**
 * Accessible tabbed interface primitive built on Base UI Tabs.
 *
 * Provides keyboard navigation, focus management, and ARIA roles (tab, tablist, tabpanel).
 */
function Tabs({ className, ...props }: TabsPrimitive.Root.Props) {
  return (
    <TabsPrimitive.Root
      data-slot="tabs"
      className={cn("flex flex-col gap-4", className)}
      {...props}
    />
  );
}

function TabsList({ className, ...props }: TabsPrimitive.List.Props) {
  return (
    <TabsPrimitive.List
      data-slot="tabs-list"
      className={cn(
        "bg-muted/70 text-muted-foreground border-border inline-flex h-10 w-full flex-wrap items-center justify-start gap-1 rounded-lg border p-1 sm:w-auto",
        className,
      )}
      {...props}
    />
  );
}

function TabsTrigger({ className, ...props }: TabsPrimitive.Tab.Props) {
  return (
    <TabsPrimitive.Tab
      data-slot="tabs-trigger"
      className={cn(
        "inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-all outline-none select-none",
        "focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-3",
        "disabled:pointer-events-none disabled:opacity-50",
        "data-active:bg-background data-active:text-foreground data-active:shadow-xs",
        "hover:text-foreground",
        className,
      )}
      {...props}
    />
  );
}

function TabsContent({ className, ...props }: TabsPrimitive.Panel.Props) {
  return (
    <TabsPrimitive.Panel
      data-slot="tabs-content"
      className={cn("flex-1 outline-none", className)}
      {...props}
    />
  );
}

export { Tabs, TabsList, TabsTrigger, TabsContent };
