<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CrmMediaFile extends Model
{
    protected $fillable = [
        'folder_id',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'restore_folder_id',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(CrmMediaFolder::class, 'folder_id');
    }

    public function restoreFolder(): BelongsTo
    {
        return $this->belongsTo(CrmMediaFolder::class, 'restore_folder_id');
    }

    public function publicUrl(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function isInTrash(): bool
    {
        $trash = CrmMediaFolder::trashFolder();

        return $trash && (int) $this->folder_id === (int) $trash->id;
    }
}
