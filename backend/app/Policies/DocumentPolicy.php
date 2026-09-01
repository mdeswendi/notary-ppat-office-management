<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Document\DocumentVisibility;
use App\Http\Controllers\Api\V1\DocumentController;
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
 * ## The sensitive-download gate is gone
 *
 * From M5.2 until M8.1 `download` ended `return ! $document->is_sensitive`,
 * refusing every sensitive download whatever the actor held: D-115 ruled that no
 * such surface ships before an audit store exists, because the capability to read
 * a KTP scan and the record of who read it belong in the same milestone. **M8.1
 * built `audit_logs` (D-123), so the gate came out and D-115 closed.**
 *
 * `documents.sensitive.download` therefore authorizes something, and the ordinary
 * and sensitive codes are both checked. {@see download()} carries the detail.
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
     *
     * **The D-115 gate is lifted at M8.1, and this is what closes D-115.**
     *
     * From M5.2 until now this method ended `return ! $document->is_sensitive`,
     * refusing every sensitive download whatever the actor held — because the
     * capability to read a KTP scan and the record of who read it belong in the
     * same milestone, and `audit_logs` did not exist. M8.1 builds it (D-123), so
     * the gate comes out exactly as M5.2 said it would.
     *
     * **The authorization was never reconstructed.** The two capability checks
     * below are the ones M5.1 wrote; all that changed is the line beneath them.
     * That was the point of keeping them rather than short-circuiting the method.
     *
     * `documents.sensitive.download` now authorizes something for the first time
     * since it was catalogued at M1.2. Every sensitive download writes a
     * `SENSITIVE_ACCESS` audit row recording the document's key and the actor —
     * **never the file's contents, and never the identity it is about** (D-105,
     * restated with more force by D-115). {@see DocumentController::download}
     * is where that write happens: in the controller rather than here, because a
     * Policy answers a question and must not have a side effect.
     */
    public function download(User $actor, Document $document): bool
    {
        if (! $this->reaches($actor, 'documents.download', $document)) {
            return false;
        }

        return $this->passesSensitivity($actor, $document, 'documents.sensitive.download');
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
     * May the actor read what this Document is attached to? (M5.3, D-118)
     *
     * Answers to `documents.view`, the same capability that opens the Document —
     * what it is attached to is part of what the document *is*, not a separate
     * resource with its own audience.
     *
     * **The stubs it returns are labels, never a way in.** A Party, Project or
     * Matter the caller cannot open still appears, because concealing it would
     * make the list lie about where a document has been filed; but the stub
     * carries a name and an id, and opening the record is that surface's own
     * decision to make.
     */
    public function viewRelations(User $actor, Document $document): bool
    {
        return $this->view($actor, $document);
    }

    /**
     * May the actor attach or detach this Document?
     *
     * **`documents.update` on the Document side**, because attaching is a
     * correction to the document's own filing rather than a new capability.
     * Registering `documents.attach` would have added a code to a canonical
     * catalogue this milestone has no authority to change (D-115: the count stays
     * at 177).
     *
     * **This is only half the question.** The record on the other end must also be
     * reachable under its own domain's view capability — `parties.view`,
     * `projects.view`, or the Matter's own `notary.`/`ppat.matters.view` — and
     * that half is decided by the controller through each domain's visibility
     * class. `documents.update` is authority over a document's filing; it is never
     * authority to discover which records exist.
     *
     * One ability covers both directions on purpose: an actor who may attach may
     * undo it. Splitting them would let a person file a document against the wrong
     * Matter and be unable to correct it.
     */
    public function attach(User $actor, Document $document): bool
    {
        return $this->update($actor, $document);
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
