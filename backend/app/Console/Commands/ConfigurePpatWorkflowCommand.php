<?php

namespace App\Console\Commands;

use App\Models\Office;
use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provision the office-approved PPAT workflow for future Matters.
 *
 * Existing Matters are deliberately untouched: workflows are instantiated by
 * CreateMatter, so this template affects only Matters created afterwards.
 */
class ConfigurePpatWorkflowCommand extends Command
{
    protected $signature = 'app:configure-ppat-workflow {office? : Office ULID or code}';

    protected $description = 'Configure the PPAT workflow for new Matters';

    public function handle(): int
    {
        $office = $this->resolveOffice();

        if ($office === null) {
            $this->error('Office not found. Pass its ULID or office code.');

            return self::FAILURE;
        }

        $existing = WorkflowTemplate::query()
            ->where('office_id', $office->getKey())
            ->where('code', 'PPAT_STANDARD')
            ->first();

        if ($existing !== null) {
            $this->info("PPAT workflow already configured for {$office->name}. No changes made.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($office): void {
            $template = new WorkflowTemplate;
            $template->office_id = $office->getKey();
            $template->code = 'PPAT_STANDARD';
            $template->name_id = 'Alur PPAT standar';
            $template->name_en = 'Standard PPAT workflow';
            $template->version = 1;
            $template->is_default = true;
            $template->is_active = true;
            $template->save();

            foreach ([
                ['code' => 'PROSES', 'name_id' => 'Proses', 'name_en' => 'Process'],
                ['code' => 'VERIFIKASI', 'name_id' => 'Verifikasi', 'name_en' => 'Verification'],
                ['code' => 'DOKUMEN', 'name_id' => 'Dokumen', 'name_en' => 'Documents'],
                ['code' => 'SELESAI', 'name_id' => 'Selesai', 'name_en' => 'Done'],
            ] as $sequence => $stage) {
                $workflowStage = new WorkflowStage;
                $workflowStage->id = (string) Str::ulid();
                $workflowStage->workflow_template_id = $template->getKey();
                $workflowStage->code = $stage['code'];
                $workflowStage->name_id = $stage['name_id'];
                $workflowStage->name_en = $stage['name_en'];
                $workflowStage->sequence_no = $sequence + 1;
                $workflowStage->target_days = null;
                $workflowStage->requires_approval = false;
                $workflowStage->approval_permission = null;
                $workflowStage->is_start_stage = $sequence === 0;
                $workflowStage->is_completion_stage = $sequence === 3;
                $workflowStage->save();
            }
        });

        $this->info("PPAT workflow configured for {$office->name}. It applies to new Matters only.");

        return self::SUCCESS;
    }

    private function resolveOffice(): ?Office
    {
        $identifier = $this->argument('office');

        if ($identifier === null) {
            return Office::query()->orderBy('id')->first();
        }

        return Office::query()
            ->whereKey($identifier)
            ->orWhere('code', $identifier)
            ->first();
    }
}
