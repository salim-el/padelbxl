<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Court extends Model
{
    /** Sports du club : le padel se joue à 4 ; le foot à 5 en salle à 10, sur des créneaux d'une heure. */
    public const SPORTS = ['padel', 'foot5'];

    public const PLAYERS = ['padel' => 4, 'foot5' => 10];

    public const FOOT_MINUTES = 60;

    protected $fillable = ['name', 'sport', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function maxPlayers(): int
    {
        return self::PLAYERS[$this->sport];
    }
}
