<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\User;
use App\Notifications\ThemeReset;
use App\Support\Audit\Audit;
use App\Support\Ui\Themes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin › Appearance (D59): the colour theme everyone sees unless they picked their own. Changing
 * the default never touches personal choices; resetting them is a separate, deliberate action
 * with a reason, typed confirmation, an audit entry and a notice to each person affected.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Appearance')]
class Appearance extends Component
{
    use PlatformAdminOnly;

    public string $default = '';

    public string $resetScope = 'one';

    public string $resetEmail = '';

    public string $resetReason = '';

    public string $resetConfirm = '';

    public function mount(Themes $themes): void
    {
        $this->authorize('settings.view');
        $this->default = $themes->default();
    }

    public function saveDefault(Themes $themes, Audit $audit): void
    {
        $this->authorize('settings.manage');
        $this->validate(['default' => ['required', Rule::in(array_keys($themes->portal()))]]);
        $before = $themes->default();
        $themes->setDefault($this->default);
        $audit->record('ui.default_theme_changed', null, ['theme' => $before], ['theme' => $this->default], label: $themes->portal()[$this->default]['label']);

        $kept = User::query()->whereNotNull('theme')->count();
        $this->dispatch('toast', type: 'success', message: 'Default theme saved.'.($kept ? " {$kept} ".str('person')->plural($kept).' who chose their own theme keep it.' : ''));
    }

    public function resetChoices(Themes $themes, Audit $audit): void
    {
        $this->authorize('settings.manage');
        $this->validate([
            'resetScope' => ['required', Rule::in(['one', 'everyone'])],
            'resetEmail' => [Rule::requiredIf($this->resetScope === 'one'), 'nullable', 'email'],
            'resetReason' => ['required', 'string', 'min:10', 'max:300'],
            'resetConfirm' => ['required', Rule::in(['RESET'])],
        ], [
            'resetConfirm.in' => 'Type RESET in capitals to confirm.',
            'resetReason.min' => 'Give a short reason (at least 10 characters). The people affected will see it.',
        ], ['resetEmail' => 'email address', 'resetReason' => 'reason', 'resetConfirm' => 'confirmation']);

        $query = User::query()->whereNotNull('theme');
        if ($this->resetScope === 'one') {
            $query->where('email', trim($this->resetEmail));
        }
        $people = $query->get();
        if ($people->isEmpty()) {
            $this->addError('resetEmail', $this->resetScope === 'one' ? 'That person uses the default already, or no account has that email.' : 'Nobody has chosen their own theme.');

            return;
        }

        User::query()->whereKey($people->modelKeys())->update(['theme' => null, 'theme_chosen_at' => null]);
        Notification::send($people, new ThemeReset(trim($this->resetReason), $themes->portal()[$themes->default()]['label']));
        $audit->record('ui.themes_reset', null, ['themes' => $people->countBy('theme')->all()], [
            'people' => $people->count(), 'scope' => $this->resetScope, 'reason' => trim($this->resetReason),
        ], label: $this->resetScope === 'one' ? $people->first()->email : 'Everyone');

        $this->reset('resetEmail', 'resetReason', 'resetConfirm');
        $this->dispatch('toast', type: 'success', message: $people->count().' '.str('person')->plural($people->count()).' now see the default theme. Each was told why.');
    }

    public function render(Themes $themes): View
    {
        return view('livewire.admin.appearance', [
            'themes' => $themes->portal(),
            'chosen' => User::query()->whereNotNull('theme')->selectRaw('theme, COUNT(*) as people')->groupBy('theme')->pluck('people', 'theme'),
            'canManage' => auth()->user()->hasPermissionIn('settings.manage'),
        ]);
    }
}
