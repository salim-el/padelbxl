<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** F24 : heures d'ouverture et durée des créneaux. Une seule ligne, lue par Clock. */
class ClubSetting extends Model
{
    protected $fillable = ['opens_at', 'closes_at', 'slot_minutes'];

    protected function casts(): array
    {
        return ['slot_minutes' => 'integer'];
    }

    /** Durée d'un créneau : celle que règle le gérant pour le padel, une heure fixe pour le foot à 5. */
    public function minutes(string $sport = 'padel'): int
    {
        return $sport === 'foot5' ? Court::FOOT_MINUTES : $this->slot_minutes;
    }

    /** Heures de départ des créneaux d'un sport : de l'ouverture, toutes les « durée » minutes, tant que le créneau finit avant la fermeture. */
    public function slots(string $sport = 'padel'): array
    {
        $minutes = $this->minutes($sport);
        $toMinutes = fn (string $t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        $slots = [];
        for ($m = $toMinutes($this->opens_at); $m + $minutes <= $toMinutes($this->closes_at); $m += $minutes) {
            $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }

        return $slots;
    }
}
