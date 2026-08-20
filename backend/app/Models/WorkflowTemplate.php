<?php

namespace App\Models;

use Database\Factories\WorkflowTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One configurable workflow an Office may run (M4.6, D-104, D-111).
 *
 * **The container, not its content.** M4.6 creates the table and seeds nothing:
 * no Notary or PPAT stage sequence, no default template, no approval point
 * (D-104). Every template in a deployment is one an office entered.
 *
 * **`office_id` and `code` are immutable**, following `ServiceType`. They are
 * identity rather than content: an Office owns the template and a code is how
 * other configuration refers to it, so changing either silently redefines what
 * existing references mean. `version` is deliberately *not* in that set — bumping
 * it is the ordinary act of editing a template.
 *
 * **`version` is a counter on this row, not a second row** (D-111). Editing a
 * template raises it in place; the previous iteration is preserved by M4.7's
 * snapshot rather than by keeping an old row, which is why `matter_workflows`
 * records both `workflow_template_id` and `workflow_version`. `CLAUDE.md`
 * section 18 requires that editing a template never retroactively change a
 * Matter already running, and the snapshot is what guarantees it.
 *
 * **`is_default` is a designation under no cardinality rule.** Several templates
 * may be default at once and none has to be, following `project_parties.is_primary`
 * (D-092). Whichever milestone reads it must choose deterministically and say
 * how, rather than assuming the database handed it exactly one.
 *
 * **No `SoftDeletes` and no `deleted_at`.** Retirement is `is_active`, exactly as
 * for Service Types: an inactive template is unavailable for new instantiation
 * and stays readable on every Matter already running it.
 */
#[Fillable([
    'name_id',
    'name_en',
    'version',
    'is_default',
    'is_active',
])]
class WorkflowTemplate extends Model
{
    /** @use HasFactory<WorkflowTemplateFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $template): void {
            foreach (['office_id', 'code'] as $attribute) {
                if ($template->isDirty($attribute)) {
                    throw new RuntimeException(
                        "workflow_templates.{$attribute} is immutable (M4.6). "
                        .'Office and code are identity rather than content: other configuration '
                        .'refers to a template by them, so changing one silently redefines what '
                        .'those references mean. Lifting this needs its own architecture decision.'
                    );
                }
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * The Service Type this template configures, or none.
     *
     * Nullable by design: an unbound template is the office's generic process,
     * and requiring a binding would make workflow configuration impossible for
     * as long as the service catalogue is empty — which M4.1 ships it (D-102).
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * The stages of this template, in configured order.
     *
     * Ordered here rather than at every call site, because a workflow read out of
     * sequence is not a workflow. `sequence_no` is unique per template, so the
     * order is total.
     */
    public function stages(): HasMany
    {
        return $this->hasMany(WorkflowStage::class)->orderBy('sequence_no');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
