<?php

namespace App\Services;

use App\Models\Consent;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Support\Collection;

/** F1 et F7 : quelles versions sont en vigueur, ce qu'un utilisateur n'a pas encore accepté, et l'enregistrement de son accord. */
class LegalConsents
{
    /** @return Collection<int, LegalDocument> la dernière version publiée de chaque document obligatoire, dans la langue demandée */
    public function current(string $locale): Collection
    {
        return collect(LegalDocument::REQUIRED)->map(function (string $type) use ($locale) {
            $version = LegalDocument::where('type', $type)->where('published_at', '<=', now())->orderByDesc('published_at')->value('version');
            if ($version === null) {
                return null;
            }
            $docs = LegalDocument::where('type', $type)->where('version', $version);

            return (clone $docs)->where('locale', $locale)->first() ?? $docs->where('locale', 'fr')->first();
        })->filter()->values();
    }

    /** @return Collection<int, LegalDocument> les versions en vigueur que l'utilisateur n'a acceptées dans aucune langue */
    public function missing(User $user): Collection
    {
        $accepted = Consent::where('user_id', $user->id)->join('legal_documents', 'legal_documents.id', '=', 'consents.legal_document_id')
            ->get(['legal_documents.type', 'legal_documents.version'])
            ->map(fn ($d) => "{$d->type}:{$d->version}");

        return $this->current($user->locale ?? 'fr')->reject(fn (LegalDocument $d) => $accepted->contains("{$d->type}:{$d->version}"))->values();
    }

    public function accept(User $user, ?string $ip): void
    {
        foreach ($this->current($user->locale ?? app()->getLocale()) as $doc) {
            Consent::firstOrCreate(['user_id' => $user->id, 'legal_document_id' => $doc->id], ['accepted_at' => now(), 'ip_address' => $ip]);
        }
    }
}
