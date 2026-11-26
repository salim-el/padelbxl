<?php

namespace App\Http\Controllers;

use App\Notifications\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formulaire « Écrire au club » (T03, F5). Le message part par e-mail à CLUB_EMAIL et n'est pas gardé en base :
 * aucune durée de conservation à gérer dans l'application.
 */
class ContactController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('contact', [
            'club' => config('invoicing.seller'),
            'sent' => $request->session()->get('contact_sent', false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $email = config('invoicing.seller.email');
        abort_if(blank($email), 503);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        // Le club lit en français, quelle que soit la langue du joueur.
        Notification::route('mail', $email)->notify((new ContactMessage($data['name'], $data['email'], $data['message']))->locale('fr'));

        return to_route('contact')->with('contact_sent', true);
    }
}
