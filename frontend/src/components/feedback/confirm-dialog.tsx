"use client";

import { type ReactElement, useState } from "react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";

type ConfirmDialogProps = {
  trigger: ReactElement;
  title: string;
  description: string;
  cancelLabel: string;
  confirmLabel: string;
  pendingLabel?: string;
  onConfirm: () => Promise<unknown> | unknown;
  destructive?: boolean;
};

/**
 * Accessible confirmation for consequential actions.
 *
 * The dialog remains open while the request runs, then closes so the feature's
 * adjacent success or error state is visible. It owns presentation only;
 * capability checks and the mutation stay with the caller.
 */
export function ConfirmDialog({
  trigger,
  title,
  description,
  cancelLabel,
  confirmLabel,
  pendingLabel = confirmLabel,
  onConfirm,
  destructive = true,
}: ConfirmDialogProps) {
  const [open, setOpen] = useState(false);
  const [isPending, setIsPending] = useState(false);

  const confirm = async () => {
    setIsPending(true);

    try {
      await onConfirm();
    } catch {
      // The caller's mutation owns and displays the domain-specific error.
    } finally {
      setOpen(false);
      setIsPending(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger render={trigger} />
      <DialogContent role="alertdialog" showCloseButton={!isPending}>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <DialogFooter>
          <DialogClose render={<Button type="button" variant="outline" disabled={isPending} />}>
            {cancelLabel}
          </DialogClose>
          <Button
            type="button"
            variant={destructive ? "destructive" : "default"}
            disabled={isPending}
            onClick={() => void confirm()}
          >
            {isPending ? pendingLabel : confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
