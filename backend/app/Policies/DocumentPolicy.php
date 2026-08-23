<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Document\DocumentVisibility;
use App\Models\Document;
use App\Models\User;

/**
 * Who may work with Documents (M5.1, D-115, D-116).
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048), so M1's
 * rules apply unchanged: canonical permissions only, a role grant with no Data
 * Scope grants nothing, an active DENY override wins, expired overrides are
 * ignored, and Spatie's direct user-permission grants never participate. No role
 * name is read anywhere, and `SUPER_ADMIN` receives no bypass.
 *
 * ## Nine independent capabilities, and none implies another
 *
 * The registry defines nine `documents.*` codes and this class honours them
 * separately — the discipline D-091 applies to Project and D-110 to
 * participation. `documents.update` does not reach `verify`; `verify` does not
 * reach `archive`; `upload` does not reach `download`.
 *
 * **The two sensitive codes are the sharpest case.** `documents.sensitive.view`
 * and `documents.sensitive.download` are **separate capabilities, not
 * escalations** (D-115). Holding `documents.view` does not imply seeing a
 * sensitive document, and holding `documents.download` does not imply
 * downloading one. Equally, the sensitive codes do not imply the ordinary ones:
 * both are checked, so an actor who somehow held only the sensitive code cannot
 * read an ordinary document through it.
 *
 * ## Sensitivity is checked here, not in the query
 *
 * {@see DocumentVisibility} deliberately ignores `is_sensitive`: it is not a Data
 * Scope, and folding it into the scope predicate would make one permission answer
 * two questions and silently reinterpret every existing `documents.view` grant.
 * The sensitivity test is a second condition applied on top of reach, which is
 * what keeps the two independently grantable.
 *
 * ## What M5.1 does not decide
 *
 * `download` is present and **no surface calls it yet.** D-115 rules that no
 * sensitive-download surface ships before an audit store exists, because the
 * capability to read a KTP scan and the record of who read it belong in the same
 * milestone. The ability is written now so the milestone that builds the surface
 * has only to call it — the way M2.1 prepared Party, M3.1 Project, M4.1 Service
 * Type and M4.2 Matter.
 *
 * There is **no `verify`, `archive` or `delete` transition rule here.** M5
 * authorizes *who* may act and never encodes *which* status may follow which
 * (`15_M5_DOCUMENT_TASK_ARCHITECTURE.md` section 10.2).
 */
class DocumentPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly DocumentVisibility $visibility,
    ) {}

    /**
     * May the actor open the Document list?
     *
     * A grant carrying only `ASSIGNED` or `TEAM` reaches nothing, so it is
     * refused outright rather than serving a reliably empty page.
     */
    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($actor, 'documents.view')
        );
    }

    public function view(User $actor, Document $document): bool
    {
        return $this->reaches($actor, 'documents.view', $document)
            && $this->passesSensitivity($actor, $document, 'documents.sensitive.view');
    }

    /**
     * May the actor file a new Document in their own Office?
     *
     * Always their own: `ALL` is reach over records that already exist, never
     * authority to decide which Office a new one belongs to.
     */
    public function create(User $actor, ?string $officeId = null): bool
    {
        return $this->visibility->permitsCreationIn(
            $actor,
            $this->resolver->resolve($actor, 'documents.upload'),
            $officeId,
        );
    }

    /**
     * May the actor add a version to this Document?
     *
     * The same capability as filing a new one — `documents.upload` — judged
     * against the existing record rather than against an Office, because the
     * Document already has one. **This is never an overwrite**: a new version is
     * added and the previous file stays exactly as it was (`CLAUDE.md` §19).
     */
    public function upload(User $actor, Document $document): bool
    {
        return $this->reaches($actor, 'documents.upload', $document)
            && $this->passesSensitivity($actor, $document, 'documents.sensitive.view');
    }

    /**
     * May the actor read this Document's file?
     *
     * Separate from `view`, which reaches metadata. Somebody may legitimately be
     * allowed to know a document exists and not to read it.
     */
    public function download(User $actor, Document $document): bool
    {
        return $this->reaches($actor, 'documents.download', $document)
            && $this->passesSensitivity($actor, $document, 'documents.sensitive.download');
    }

    /**
     * Correct a Document's metadata — never its file, and never its Office.
     */
    public function update(User $actor, Document $document): bool
    {
        return $this->reaches($actor, 'documents.update', $document)
            && $this->passesSensitivity($actor, $document, 'documents.sensitive.view');
    }

    public function verify(User $actor, Document $document): bool
    {
        return $this->reaches($actor, 'documents.verify', $document)
            && $this->passesSensitivity($actor, $document, 'documents.sensitive.view');
    }

    public function archive(User $actor, Document $document): bool
    {
        return $this->reaches($actor, 'documents.archive', $document)
            && $this->passesSensitivity($actor, $document, 'documents.sensitive.view');
    }

    /**
     * Delete a Document.
     *
     * `02_MENU_AND_PERMISSIONS.md` section 13 states that `documents.delete`
     * *"must be heavily restricted"* and that archive, void or supersede are
     * preferred for legal documents. **M5.1 builds no deletion path**; the
     * ability exists so the milestone that considers one starts from a decision
     * rather than an omission.
     */
    public function delete(User $actor, Document $document): bool
    {
        return $this->reaches($actor, 'documents.delete', $document)
            && $this->passesSensitivity($actor, $document, 'documents.sensitive.view');
    }

    private function reaches(User $actor, string $permission, Document $document): bool
    {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $document,
        );
    }

    /**
     * A sensitive Document additionally requires its own capability.
     *
     * An ordinary Document passes untouched — the sensitive codes are a second
     * condition, not a replacement, so they can never be a way to reach something
     * the ordinary code would refuse.
     */
    private function passesSensitivity(User $actor, Document $document, string $permission): bool
    {
        if (! $document->is_sensitive) {
            return true;
        }

        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $document,
        );
    }
}
