"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { hasFieldError, toWarkahErrorKey } from "@/features/warkah/warkah-errors";
import { getPartyDirectory, partyDirectoryKeys } from "@/services/parties";
import { addWarkahItem, warkahKeys } from "@/services/warkah";

/**
 * Add a line to a Warkah (M7.4, D-121).
 *
 * ## The office writes its own checklist, because nobody has written one for it
 *
 * There is no "generate the standard items for this deed type" control, and building
 * one would answer open question three — *"what is the mandatory Warkah composition per
 * deed type?"* — which `CLAUDE.md` section 62 names among the things not to invent. No
 * requirement template drives this (D-104); every line is one somebody typed.
 *
 * **`requirement_code` is optional**, which inverts the M7.4 brief. It refers to no
 * catalogue, so requiring it would make an office invent a code to get past validation
 * — the argument D-102 made for `matters.service_type_id`.
 *
 * ## Both titles are required, and there is no status field
 *
 * `title_id` and `title_en` are **bilingual database fields**, not UI strings
 * (`CLAUDE.md` section 10) — a line with one language filled in renders blank for half
 * the office.
 *
 * **No status control exists**, and that is not an omission. The brief specified
 * `MISSING / RECEIVED / UNDER_REVIEW / VERIFIED / REJECTED / NOT_APPLICABLE`;
 * `ppat_warkah_items.status` has no values in the ERD, and the API refuses the field on
 * presence. What a line's state actually is — whether anything has been filed against
 * it — follows from attaching a document, which is a separate capability
 * (`ppat.warkah.upload`) and a separate control.
 *
 * ## The Party picker searches the directory, and the server decides
 *
 * Candidates come from `GET /api/v1/parties`, which applies `parties.view` and
 * `companies.view` at their own scopes — so this can never surface a Party the actor
 * could not already reach. The backend resolves the choice through the same visibility
 * again and answers one indistinguishable field error for an unreachable, wrong-Office
 * or nonexistent Party.
 *
 * A line's party is optional: an identity document belongs to a person, a land
 * certificate belongs to the transaction.
 */
export function WarkahItemForm({ deedId }: { deedId: string }) {
  const t = useTranslations("warkah");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [titleId, setTitleId] = useState("");
  const [titleEn, setTitleEn] = useState("");
  const [requirementCode, setRequirementCode] = useState("");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [partyId, setPartyId] = useState("");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => setSearch(searchInput.trim()), 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const candidateQuery = { page: 1, per_page: 20, search, party_type: "" as const };

  const candidates = useQuery({
    queryKey: partyDirectoryKeys.list(candidateQuery),
    queryFn: () => getPartyDirectory(candidateQuery),
    enabled: open,
  });

  const mutation = useMutation({
    mutationFn: () =>
      addWarkahItem(deedId, {
        title_id: titleId.trim(),
        title_en: titleEn.trim(),
        requirement_code: requirementCode.trim() === "" ? null : requirementCode.trim(),
        party_id: partyId === "" ? null : partyId,
      }),
    onSuccess: async () => {
      setError(null);
      setOpen(false);
      setTitleId("");
      setTitleEn("");
      setRequirementCode("");
      setPartyId("");
      setSearchInput("");

      await queryClient.invalidateQueries({ queryKey: warkahKeys.items(deedId) });
      await queryClient.invalidateQueries({ queryKey: warkahKeys.forDeed(deedId) });
      await queryClient.invalidateQueries({ queryKey: warkahKeys.all() });
    },
    onError: (failure: unknown) => {
      setError(
        hasFieldError(failure, "party_id")
          ? t("validation.partyUnavailable")
          : t(`errors.${toWarkahErrorKey(failure)}`),
      );
    },
  });

  if (!open) {
    return (
      <div>
        <Button variant="outline" size="sm" className="gap-2" onClick={() => setOpen(true)}>
          <Plus aria-hidden="true" />
          {t("addItem")}
        </Button>
      </div>
    );
  }

  return (
    <form
      className="border-border flex flex-col gap-4 rounded-lg border p-4"
      onSubmit={(event) => {
        event.preventDefault();
        setError(null);

        if (titleId.trim() !== "" && titleEn.trim() !== "") {
          mutation.mutate();
        }
      }}
    >
      <h4 className="text-sm font-medium">{t("addItem")}</h4>

      {error ? (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <Label htmlFor="warkah-title-id">{t("titleId")}</Label>
          <Input
            id="warkah-title-id"
            value={titleId}
            onChange={(event) => setTitleId(event.target.value)}
          />
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="warkah-title-en">{t("titleEn")}</Label>
          <Input
            id="warkah-title-en"
            value={titleEn}
            onChange={(event) => setTitleEn(event.target.value)}
          />
        </div>
      </div>

      <p className="text-muted-foreground text-xs">{t("titleHint")}</p>

      <div className="flex flex-col gap-2">
        <Label htmlFor="warkah-requirement">{t("requirementCode")}</Label>
        <Input
          id="warkah-requirement"
          value={requirementCode}
          onChange={(event) => setRequirementCode(event.target.value)}
        />
        <p className="text-muted-foreground text-xs">{t("requirementCodeHint")}</p>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="warkah-party-search">{t("findParty")}</Label>
        <Input
          id="warkah-party-search"
          placeholder={t("findPartyPlaceholder")}
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
        />
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="warkah-party">{t("party")}</Label>
        <Select
          id="warkah-party"
          value={partyId}
          onChange={(event) => setPartyId(event.target.value)}
        >
          <option value="">{t("noParty")}</option>
          {(candidates.data?.data ?? []).map((party) => (
            <option key={party.id} value={party.id}>
              {party.display_name ?? party.id}
            </option>
          ))}
        </Select>
        <p className="text-muted-foreground text-xs">{t("partyHint")}</p>
      </div>

      <div className="flex gap-2">
        <Button
          type="submit"
          size="sm"
          disabled={titleId.trim() === "" || titleEn.trim() === "" || mutation.isPending}
        >
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
        <Button type="button" variant="outline" size="sm" onClick={() => setOpen(false)}>
          {tActions("cancel")}
        </Button>
      </div>
    </form>
  );
}
