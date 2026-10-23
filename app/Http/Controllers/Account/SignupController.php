<?php

namespace App\Http\Controllers\Account;

use App\Actions\Signup\SignUpBusiness;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Account\SignIn;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Self-serve sign-up from the website (D65): pick a plan, create the owner's account, start the free trial.
 */
class SignupController extends Controller
{
    /** Hidden field people never fill in; form-filling bots usually do. */
    public const TRAP = 'website';

    public function show(Request $request): View
    {
        $plans = $this->plans();
        $wanted = (string) $request->query('plan', '');
        // A referral link (D90): remembered for this visit, whichever page they sign up from.
        if (preg_match('/^[A-Za-z0-9]{6,12}$/', (string) $request->query('ref')) === 1) {
            $request->session()->put('referral', strtoupper((string) $request->query('ref')));
        }
        $preselected = $plans->contains('slug', $wanted) ? $wanted : $plans->first()?->slug;

        return view('auth.signup', [
            'enabled' => (bool) config('signup.enabled'),
            'plans' => $plans,
            'selected' => old('plan', $preselected),
            'documents' => array_filter(config('account.legal', []), fn (array $d) => in_array('client', $d['roles'], true)),
        ]);
    }

    public function store(Request $request, SignUpBusiness $signUp, SignIn $signIn): RedirectResponse
    {
        abort_unless(config('signup.enabled'), 404);

        // A bot filled the hidden field: look like it worked, create nothing.
        if (filled($request->input(self::TRAP))) {
            return redirect()->route('login')->with('status', 'Thanks! Check your email to finish signing up.');
        }

        $plans = $this->plans();
        $data = $request->validate([
            'business' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'plan' => [$plans->isEmpty() ? 'nullable' : 'required', Rule::in($plans->pluck('slug')->all())],
            'accept' => ['accepted'],
        ], [
            'email.unique' => 'This email already has a SureHelp account. Sign in instead, or reset your password.',
            'accept.accepted' => 'Please tick the box to accept the terms.',
            'plan.in' => 'Please choose one of the plans.',
        ]);

        $plan = $plans->firstWhere('slug', $data['plan'] ?? null);
        $user = $signUp->handle([
            'business' => $data['business'],
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'ref' => $request->session()->pull('referral'),
        ], $plan, $request);

        $signIn->complete($user, $request, 'signup');
        $request->session()->put('url.intended', route('app.setup'));

        return redirect()->route('verification.notice');
    }

    /** @return Collection<int, Plan> */
    private function plans(): Collection
    {
        return Plan::query()->where('is_active', true)->where('is_public', true)->ordered()->get();
    }
}
