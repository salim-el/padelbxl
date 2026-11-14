<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Preuve qu'un utilisateur a accepté une version précise d'un document légal, avec la date. */
class Consent extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'legal_document_id', 'accepted_at', 'ip_address'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }
}
