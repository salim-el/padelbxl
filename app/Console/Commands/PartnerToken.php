<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PartnerToken extends Command
{
    protected $signature = 'api:partner-token {email : e-mail du gérant responsable de l’appareil} {name : nom de l’appareil, par exemple « LED terrain 1 »}';

    protected $description = 'Crée le jeton d’un appareil du club (borne, contrôleur LED), valable un an, révocable depuis la base';

    public function handle(): int
    {
        $owner = User::where('email', $this->argument('email'))->whereIn('role', ['manager', 'admin'])->where('status', 'active')->first();
        if ($owner === null) {
            $this->error('Aucun gérant actif avec cet e-mail.');

            return self::FAILURE;
        }
        $token = $owner->createToken($this->argument('name'), ['terminal'], now()->addYear());
        activity('api')->causedBy($owner)->performedOn($token->accessToken)->event('device-token-created')->log('Jeton d’appareil créé');
        // Le jeton en clair ne s'affiche qu'une fois : seule son empreinte est gardée en base.
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
