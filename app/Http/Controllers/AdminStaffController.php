<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Models\User;
use App\Services\StaffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** F35 : comptes du personnel et rôles, réservés à l'administrateur. */
class AdminStaffController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('administer-platform'), 403);

        return Inertia::render('admin/staff', [
            'staff' => User::whereIn('role', User::STAFF_ROLES)->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'two_factor' => $u->two_factor_confirmed_at !== null,
                'is_me' => $u->is($request->user()),
            ]),
        ]);
    }

    public function store(Request $request, StaffService $staff): RedirectResponse
    {
        abort_unless($request->user()->can('administer-platform'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role' => ['required', Rule::in(StaffService::ROLES)],
        ]);
        try {
            $user = $staff->invite($request->user(), $data['name'], $data['email'], $data['role']);
        } catch (BookingException $e) {
            return back()->withErrors(['staff' => $e->getMessage()]);
        }

        return back()->with('success', "{$user->name} fait partie du personnel. Un e-mail lui est envoyé.");
    }

    public function update(Request $request, User $user, StaffService $staff): RedirectResponse
    {
        abort_unless($request->user()->can('administer-platform'), 403);
        $data = $request->validate(['role' => ['required', Rule::in([...StaffService::ROLES, 'player'])]]);
        try {
            $staff->changeRole($request->user(), $user, $data['role']);
        } catch (BookingException $e) {
            return back()->withErrors(['staff' => $e->getMessage()]);
        }

        return back()->with('success', $data['role'] === 'player' ? "{$user->name} ne fait plus partie du personnel." : "Rôle de {$user->name} modifié.");
    }

    public function resend(Request $request, User $user, StaffService $staff): RedirectResponse
    {
        abort_unless($request->user()->can('administer-platform'), 403);
        try {
            $staff->resendInvitation($user);
        } catch (BookingException $e) {
            return back()->withErrors(['staff' => $e->getMessage()]);
        }

        return back()->with('success', "Nouveau lien envoyé à {$user->email}.");
    }
}
