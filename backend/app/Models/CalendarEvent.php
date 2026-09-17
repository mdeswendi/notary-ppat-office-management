<?php

namespace App\Models;

use App\Domains\Calendar\Enums\CalendarEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['title', 'description', 'event_type', 'starts_at', 'ends_at', 'location', 'project_id', 'matter_id'])]
class CalendarEvent extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public function office(): BelongsTo { return $this->belongsTo(Office::class); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function matter(): BelongsTo { return $this->belongsTo(Matter::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    protected function casts(): array
    {
        return [
            'event_type' => CalendarEventType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
