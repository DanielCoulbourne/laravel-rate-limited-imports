<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportedItem extends Model
{
    protected $fillable = [
        'import_id',
        'name',
        'description',
        'price',
        'last_failed_at',
        'failure_reason',
        'failure_count',
    ];

    protected $casts = [
        'last_failed_at' => 'datetime',
    ];

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * Mark this item as failed
     */
    public function markAsFailed(string $reason): void
    {
        $this->update([
            'last_failed_at' => now(),
            'failure_reason' => $reason,
            'failure_count' => $this->failure_count + 1,
        ]);
    }

    /**
     * Check if this item has permanently failed
     * (failed and won't be retried)
     */
    public function hasPermanentlyFailed(): bool
    {
        return $this->last_failed_at !== null;
    }
}
