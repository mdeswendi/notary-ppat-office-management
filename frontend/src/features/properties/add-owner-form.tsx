"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { hasFieldError, toPropertyErrorKey } from "@/features/properties/property-errors";
import { addPropertyOwner, propertyKeys } from "@/services/properties";
import { getPartyDirectory, partyDirectoryKeys } from "@/services/parties";

/**
 * Add a link to a chain of title (M7.3, D-121).
 *
 * ## The one control that matters: which act is this?
 *
 * ```text
 * Add a co-owner     leaves the existing holders current  (default)
 * Record a transfer  closes them at this link's start date
 * ```
 *
 * **The M7.3 brief described only the second** and stated *"hanya satu owner yang bisa
 * `is_current` = true per property"*. The M7 lock section 7.2 rules that out by name: a
 * Property legitimately has **several** current owners at once, each with a share, and
 * an M7.1 test asserts two at 50% each. Closing the previous holders on every insert
 * would make co-ownership unrepresentable — and co-ownership is ordinary for Indonesian
 * land.
 *
 * So the choice is explicit, and **adding a co-owner is the default**: it is the option
 * that ends nobody's recorded ownership. A wrong transfer silently writes an end date
 * onto somebody's title; a wrong co-owner leaves a list the office can see is wrong and
 * fix by closing a link.
 *
 * The radio group is offered only when there *are* current holders — with an empty
 * chain the two acts are identical, and a choice that changes nothing is noise.
 *
 * ## The Party picker searches the directory, and the server decides
 *
 * Candidates come from `GET /api/v1/parties`, which applies `parties.view` and
 * `companies.view` at their own scopes — so this picker can never surface a Party the
 * actor could not already reach in the directory. The backend then resolves the choice
 * through the same visibility again and answers one indistinguishable field error for
 * an unreachable, wrong-Office or nonexistent Party. No Party permission was widened to
 * populate a control.
 *
 * ## The share is optional and unbounded above by anything but arithmetic
 *
 * 0–100 per link, because that is what a percentage is. **No sum across co-owners is
 * validated** — whether shares must total 100 is a rule about Indonesian co-ownership
 * that `CLAUDE.md` section 62 forbids inventing, and the M7 lock records it as open.
 * A link with no share at all is accepted: an office recording inherited title may have
 * a name and no figure.
 */
export function AddOwnerForm({
  propertyId,
  hasCurrentOwners,
}: {
  propertyId: string;
  hasCurrentOwners: boolean;
}) {
  const t = useTranslations("properties");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [partyId, setPartyId] = useState("");
  const [share, setShare] = useState("");
  const [effectiveFrom, setEffectiveFrom] = useState("");
  const [supersedes, setSupersedes] = useState(false);
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
      addPropertyOwner(propertyId, {
        party_id: partyId,
        ownership_percentage: share.trim() === "" ? null : Number(share),
        effective_from: effectiveFrom,
        supersedes_current: supersedes,
      }),
    onSuccess: async () => {
      setError(null);
      setOpen(false);
      setPartyId("");
      setShare("");
      setEffectiveFrom("");
      setSupersedes(false);
      setSearchInput("");

      await queryClient.invalidateQueries({ queryKey: propertyKeys.all() });
    },
    onError: (failure: unknown) => {
      setError(
        hasFieldError(failure, "party_id")
          ? t("validation.partyUnavailable")
          : t(`errors.${toPropertyErrorKey(failure)}`),
      );
    },
  });

  if (!open) {
    return (
      <div>
        <Button variant="outline" size="sm" className="gap-2" onClick={() => setOpen(true)}>
          <Plus aria-hidden="true" />
          {t("ownership.addOwner")}
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

        if (partyId !== "" && effectiveFrom !== "") {
          mutation.mutate();
        }
      }}
    >
      <h3 className="text-sm font-medium">{t("ownership.addOwner")}</h3>

      {error ? (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      ) : null}

      {/*
        Two acts, stated rather than implied. Offered only when there are current
        holders to supersede — see the component docblock.
      */}
      {hasCurrentOwners ? (
        <fieldset className="flex flex-col gap-2">
          <legend className="text-sm font-medium">{t("ownership.actLegend")}</legend>

          <label className="flex items-start gap-2 text-sm">
            <input
              type="radio"
              name="ownership-act"
              className="mt-1"
              checked={!supersedes}
              onChange={() => setSupersedes(false)}
            />
            <span>
              {t("ownership.actCoOwner")}
              <span className="text-muted-foreground block text-xs">
                {t("ownership.actCoOwnerHint")}
              </span>
            </span>
          </label>

          <label className="flex items-start gap-2 text-sm">
            <input
              type="radio"
              name="ownership-act"
              className="mt-1"
              checked={supersedes}
              onChange={() => setSupersedes(true)}
            />
            <span>
              {t("ownership.actTransfer")}
              <span className="text-muted-foreground block text-xs">
                {t("ownership.actTransferHint")}
              </span>
            </span>
          </label>
        </fieldset>
      ) : null}

      <div className="flex flex-col gap-2">
        <Label htmlFor="owner-search">{t("ownership.findParty")}</Label>
        <Input
          id="owner-search"
          placeholder={t("ownership.findPartyPlaceholder")}
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
        />
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor="owner-party">{t("ownership.party")}</Label>
        <Select
          id="owner-party"
          value={partyId}
          onChange={(event) => setPartyId(event.target.value)}
        >
          <option value="">{t("ownership.selectParty")}</option>
          {(candidates.data?.data ?? []).map((party) => (
            <option key={party.id} value={party.id}>
              {party.display_name ?? party.id}
            </option>
          ))}
        </Select>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <Label htmlFor="owner-share">{t("ownership.percentage")}</Label>
          <Input
            id="owner-share"
            type="number"
            min={0}
            max={100}
            step="0.01"
            value={share}
            onChange={(event) => setShare(event.target.value)}
          />
          <p className="text-muted-foreground text-xs">{t("ownership.percentageHint")}</p>
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="owner-from">{t("ownership.effectiveFrom")}</Label>
          <Input
            id="owner-from"
            type="date"
            value={effectiveFrom}
            onChange={(event) => setEffectiveFrom(event.target.value)}
          />
        </div>
      </div>

      <div className="flex gap-2">
        <Button
          type="submit"
          size="sm"
          disabled={partyId === "" || effectiveFrom === "" || mutation.isPending}
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
