<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Account\LegalDocuments;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Accepting the current Terms, Privacy Policy and (for businesses) DPA (spec CMP-06).
 */
class TermsController extends Controller
{
    public function show(Request $request, LegalDocuments $legal): View|RedirectResponse
    {
        $pending = $legal->pendingFor($request->user());
        if ($pending === []) {
            return redirect()->to($request->user()->homeUrl());
        }

        return view('auth.terms', ['documents' => $pending, 'updated' => $legal->hasAcceptedBefore($request->user())]);
    }

    public function accept(Request $request, LegalDocuments $legal, Audit $audit): RedirectResponse
    {
        $request->validate(['accept' => ['accepted']], ['accept.accepted' => 'Please tick the box to accept.']);

        $user = $request->user();
        $legal->accept($user, $request);
        $request->session()->put('legal.ok', $legal->fingerprint($user));
        $audit->record('legal.accepted', $user, new: collect($legal->requiredFor($user))->map(fn (array $d) => $d['version'])->all());

        return redirect()->to($request->session()->pull('url.intended', $user->homeUrl()));
    }
}
