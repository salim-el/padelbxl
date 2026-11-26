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

    /** Heures de départ des créneaux : de l'ouverture, toutes les « durée » minutes, tant que le créneau finit avant la fermeture. */
    public function slots(): array
    {
        $toMinutes = fn (string $t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        $slots = [];
        for ($m = $toMinutes($this->opens_at); $m + $this->slot_minutes <= $toMinutes($this->closes_at); $m += $this->slot_minutes) {
            $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }

        return $slots;
    }
}
