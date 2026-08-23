"use client";

import { useEffect } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useForm, type UseFormRegisterReturn } from "react-hook-form";
import { z } from "zod";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { toDocumentErrorKey } from "@/features/documents/document-errors";
import { useRouter } from "@/i18n/navigation";
import {
  documentQueryKeys,
  getDocument,
  getDocumentOptions,
  updateDocument,
} from "@/services/documents";

/**
 * Correct a Document's metadata.
 *
 * **The file is not here, and cannot be.** A replacement is a new version, never
 * an edit (`CLAUDE.md` section 19), and the API answers 422 to a `file` key on
 * this endpoint. A form offering a file picker that silently did nothing would be
 * worse than one that does not offer it.
 *
 * **`is_sensitive` is disabled once the document is settled.** Verification is the
 * moment somebody accepted the document as what it claims to be, classification
 * included; flipping the flag afterwards would silently redefine which capability
 * a download answers to, and the backend refuses it with a 422. The control is
 * shown in its real state rather than hidden, so a person can see what the value
 * is and why it cannot move.
 */
export function DocumentEditForm({ documentId }: { documentId: string }) {
  const t = useTranslations("documents");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: documentQueryKeys.detail(documentId),
    queryFn: () => getDocument(documentId),
  });

  const options = useQuery({
    queryKey: documentQueryKeys.options(),
    queryFn: getDocumentOptions,
  });

  const document = query.data;

  // Mirrors DocumentStatus::locksSensitivity() on the backend. Duplicated
  // deliberately and kept trivial: it decides whether a control is disabled, and
  // the API refuses the change regardless of what the browser believes.
  const sensitivityLocked =
    document !== undefined && ["VERIFIED", "FINAL", "ARCHIVED"].includes(document.status);

  const schema = z.object({
    title: z
      .string()
      .trim()
      .min(1, { message: t("validation.titleRequired") })
      .max(255, { message: t("validation.tooLong") }),
    document_type_code: z
      .string()
      .trim()
      .max(50, { message: t("validation.tooLong") }),
    is_sensitive: z.boolean(),
    document_date: z.string().trim(),
    expiry_date: z.string().trim(),
    notes: z.string().trim(),
  });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      title: "",
      document_type_code: "",
      is_sensitive: false,
      document_date: "",
      expiry_date: "",
      notes: "",
    },
  });

  const { reset } = form;

  useEffect(() => {
    if (document) {
      reset({
        title: document.title,
        document_type_code: document.document_type_code ?? "",
        is_sensitive: document.is_sensitive,
        document_date: document.document_date ?? "",
        expiry_date: document.expiry_date ?? "",
        notes: document.notes ?? "",
      });
    }
  }, [document, reset]);

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const blank = (value: string) => (value.trim() === "" ? null : value.trim());

      return updateDocument(documentId, {
        title: values.title.trim(),
        document_type_code: blank(values.document_type_code),
        is_sensitive: values.is_sensitive,
        document_date: blank(values.document_date),
        expiry_date: blank(values.expiry_date),
        notes: blank(values.notes),
      });
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: documentQueryKeys.all() });

      router.push(`/documents/${documentId}`);
    },
    onError: (error: unknown) => {
      form.setError("root", { message: t(`errors.${toDocumentErrorKey(error)}`) });
    },
  });

  const onSubmit = form.handleSubmit((values) => {
    form.clearErrors("root");
    mutation.mutate(values);
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        <Skeleton className="h-32 w-full max-w-2xl" />
      </div>
    );
  }

  if (query.isError || !document) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toDocumentErrorKey(query.error)}`)}
      />
    );
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex max-w-2xl flex-col gap-6">
      {form.formState.errors.root ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {form.formState.errors.root.message}
        </p>
      ) : null}

      <div className="flex flex-col gap-1">
        <span className="text-sm font-medium">{t("referenceLabel")}</span>
        <span className="text-muted-foreground font-mono text-sm">{document.document_number}</span>
        <span className="text-muted-foreground text-xs">{t("referenceHint")}</span>
      </div>

      <Field
        id="title"
        label={t("titleLabel")}
        registration={form.register("title")}
        error={form.formState.errors.title?.message}
      />

      <div className="flex flex-col gap-2">
        <Label htmlFor="document_type_code">{t("typeLabel")}</Label>
        <Input
          id="document_type_code"
          list="document-type-suggestions"
          aria-invalid={form.formState.errors.document_type_code !== undefined}
          {...form.register("document_type_code")}
        />
        <datalist id="document-type-suggestions">
          {(options.data?.document_types ?? []).map((code) => (
            <option key={code} value={code} />
          ))}
        </datalist>
        <p className="text-muted-foreground text-xs">{t("typeHint")}</p>
      </div>

      <div className="border-border flex items-start gap-3 rounded-md border px-3 py-3">
        <Checkbox
          id="is_sensitive"
          checked={form.watch("is_sensitive")}
          disabled={sensitivityLocked}
          onCheckedChange={(checked) => form.setValue("is_sensitive", checked === true)}
        />
        <div className="flex flex-col gap-1">
          <Label htmlFor="is_sensitive">{t("sensitiveLabel")}</Label>
          <p className="text-muted-foreground text-xs">
            {sensitivityLocked ? t("sensitiveLocked") : t("sensitiveHint")}
          </p>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="document_date"
          label={t("documentDateLabel")}
          registration={form.register("document_date")}
          error={form.formState.errors.document_date?.message}
          type="date"
        />

        <Field
          id="expiry_date"
          label={t("expiryDateLabel")}
          registration={form.register("expiry_date")}
          error={form.formState.errors.expiry_date?.message}
          type="date"
        />
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="notes">{t("notesLabel")}</Label>
        <textarea
          id="notes"
          rows={4}
          className="border-border bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
          {...form.register("notes")}
        />
      </div>

      <div>
        <Button type="submit" disabled={mutation.isPending}>
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
      </div>
    </form>
  );
}

function Field({
  id,
  label,
  registration,
  error,
  type = "text",
}: {
  id: string;
  label: string;
  registration: UseFormRegisterReturn;
  error?: string;
  type?: string;
}) {
  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} type={type} aria-invalid={error !== undefined} {...registration} />
      {error ? (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      ) : null}
    </div>
  );
}
