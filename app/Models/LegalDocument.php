<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Une version d'un document légal, dans une langue. Une version publiée ne change plus : on en publie une nouvelle. */
class LegalDocument extends Model
{
    public $timestamps = false;

    /** Les deux documents que le joueur accepte avant de réserver (F1, F7). */
    public const REQUIRED = ['terms', 'privacy'];

    protected $fillable = ['type', 'version', 'locale', 'content', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
