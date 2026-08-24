"use client";

import { useRef, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Upload, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { useForm, type UseFormRegisterReturn } from "react-hook-form";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toDocumentErrorKey } from "@/features/documents/document-errors";
import { useRouter } from "@/i18n/navigation";
import { documentQueryKeys, getDocumentOptions, uploadDocument } from "@/services/documents";

/**
 * File a Document and its first version.
 *
 * **Six things this form deliberately does not offer**, each because the backend
 * refuses it outright rather than ignoring it:
 *
 *   Office            a document is filed in your own Office (D-116), never chosen.
 *   Document number   allocated server-side and immutable. Shown after the upload;
 *                     never an input.
 *   Status            a new document is RECEIVED, and the only changes the product
 *                     offers are verify and archive, on the detail page (D-117).
 *   Version number    the first version is 1; a correction is a new version.
 *   Checksum          computed from the bytes that actually land, never claimed.
 *   Storage path      decided by the system and never returned at all (D-114).
 *
 * A form that showed those fields and silently dropped them would tell the user
 * their choice was accepted. The API returns 422 for each, so this form does not
 * present a choice that does not exist.
 *
 * **`document_type_code` is a free-text field with suggestions, not a dropdown.**
 * The code is opaque and nothing validates against the list (D-115, D-116) — an
 * office that files something the list does not name must be able to type it. A
 * `<select>` would turn a set of examples into a catalogue the canonical documents
 * never claimed.
 *
 * **`is_sensitive` is a visible, deliberate choice** and is never inferred from
 * the type (D-115). Deriving it would encode which document kinds are sensitive,
 * a judgement that varies by office.
 *
 * Zod covers shape, size and type for usability. The Form Request stays
 * authoritative — and its file check reads the file's *actual* type, where this
 * one can only read what the browser reports.
 */
export function DocumentUploadForm({
  relatedTo,
  onUploaded,
}: {
  relatedTo?: { party_id?: string; project_id?: string; matter_id?: string };
  onUploaded?: (documentId: string) => void;
}) {
  const t = useTranslations("documents");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const [file, setFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const options = useQuery({
    queryKey: documentQueryKeys.options(),
    queryFn: getDocumentOptions,
  });

  const maxKilobytes = options.data?.max_upload_kilobytes ?? 20480;
  const allowedMimeTypes = options.data?.mime_types ?? [];

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

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      if (file === null) {
        // Unreachable through the submit handler, which checks first. Present so
        // the mutation cannot be called into a state it cannot honour.
        throw new Error("no file");
      }

      const blank = (value: string) => (value.trim() === "" ? undefined : value.trim());

      return uploadDocument({
        title: values.title.trim(),
        document_type_code: blank(values.document_type_code),
        is_sensitive: values.is_sensitive,
        document_date: blank(values.document_date),
        expiry_date: blank(values.expiry_date),
        notes: blank(values.notes),
        file,
        related_to: relatedTo,
      });
    },
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: documentQueryKeys.all() });

      if (onUploaded) {
        onUploaded(saved.id);

        return;
      }

      router.push(`/documents/${saved.id}`);
    },
    onError: (error: unknown) => {
      form.setError("root", { message: t(`errors.${toDocumentErrorKey(error)}`) });
    },
  });

  /**
   * Accept a file only if the browser reports an allowed type and size.
   *
   * **This is convenience, not a control.** The browser's reported type comes
   * from the file extension and is trivially wrong; the backend re-reads the
   * file's actual contents (`mimetypes`, not `mimes`) and is what decides. Checking
   * here saves somebody a 20 MB upload that was always going to be refused.
   */
  const accept = (candidate: File | undefined) => {
    if (!candidate) {
      return;
    }

    if (allowedMimeTypes.length > 0 && !allowedMimeTypes.includes(candidate.type)) {
      setFile(null);
      setFileError(t("validation.fileType"));

      return;
    }

    if (candidate.size > maxKilobytes * 1024) {
      setFile(null);
      setFileError(t("validation.fileTooLarge", { megabytes: Math.floor(maxKilobytes / 1024) }));

      return;
    }

    setFile(candidate);
    setFileError(null);

    // Fill an empty title from the filename, minus the extension. A convenience
    // that never overwrites something the person typed.
    if (form.getValues("title").trim() === "") {
      form.setValue("title", candidate.name.replace(/\.[^.]+$/, ""));
    }
  };

  const onSubmit = form.handleSubmit((values) => {
    form.clearErrors("root");

    if (file === null) {
      setFileError(t("validation.fileRequired"));

      return;
    }

    mutation.mutate(values);
  });

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

      <div className="flex flex-col gap-2">
        <Label htmlFor="document-file">{t("fileLabel")}</Label>

        {/*
          A drop target that is also a real file input, so it works by keyboard
          and with assistive technology exactly as a plain input does. The visible
          box is a label pointing at the input rather than a div pretending to be
          one — a click and a drop both end at the same place, and neither depends
          on JavaScript for the accessible path.
        */}
        <label
          htmlFor="document-file"
          onDragOver={(event) => {
            event.preventDefault();
            setIsDragging(true);
          }}
          onDragLeave={() => setIsDragging(false)}
          onDrop={(event) => {
            event.preventDefault();
            setIsDragging(false);
            accept(event.dataTransfer.files[0]);
          }}
          className={`border-border flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed px-6 py-8 text-center text-sm transition-colors ${
            isDragging ? "border-primary bg-primary/5" : "hover:bg-muted/40"
          }`}
        >
          <Upload aria-hidden="true" className="text-muted-foreground size-6" />
          <span className="font-medium">{t("dropHint")}</span>
          <span className="text-muted-foreground text-xs">
            {t("fileConstraints", { megabytes: Math.floor(maxKilobytes / 1024) })}
          </span>
        </label>

        <Input
          id="document-file"
          ref={inputRef}
          type="file"
          className="sr-only"
          accept={allowedMimeTypes.join(",")}
          aria-invalid={fileError !== null}
          onChange={(event) => accept(event.target.files?.[0])}
        />

        {file ? (
          <div className="border-border flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm">
            <span className="truncate">{file.name}</span>
            <div className="flex shrink-0 items-center gap-2">
              <span className="text-muted-foreground text-xs">{formatBytes(file.size)}</span>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                aria-label={t("removeFile")}
                onClick={() => {
                  setFile(null);
                  setFileError(null);

                  if (inputRef.current) {
                    inputRef.current.value = "";
                  }
                }}
              >
                <X aria-hidden="true" className="size-4" />
              </Button>
            </div>
          </div>
        ) : null}

        {fileError ? (
          <p role="alert" className="text-destructive text-sm">
            {fileError}
          </p>
        ) : null}
      </div>

      <Field
        id="title"
        label={t("titleLabel")}
        registration={form.register("title")}
        error={form.formState.errors.title?.message}
      />

      <div className="flex flex-col gap-2">
        <Label htmlFor="document_type_code">{t("typeLabel")}</Label>
        {/*
          Free text with suggestions. `list` offers the common codes without
          restricting the field to them, which is exactly the relationship the
          backend has with this value: opaque, and validated for length only.
        */}
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
          onCheckedChange={(checked) => form.setValue("is_sensitive", checked === true)}
        />
        <div className="flex flex-col gap-1">
          <Label htmlFor="is_sensitive">{t("sensitiveLabel")}</Label>
          <p className="text-muted-foreground text-xs">{t("sensitiveHint")}</p>
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
          {mutation.isPending ? t("uploading") : tActions("save")}
        </Button>
      </div>

      {/*
        An upload has no progress events through the shared client, so the
        interface says "working" rather than drawing a bar that would be a
        fabrication. A determinate bar that is not measuring anything is worse
        than none.
      */}
      {mutation.isPending ? (
        <p aria-live="polite" className="text-muted-foreground text-sm">
          {t("uploadInProgress")}
        </p>
      ) : null}
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

function formatBytes(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`;
  }

  if (bytes < 1024 * 1024) {
    return `${Math.round(bytes / 1024)} KB`;
  }

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
