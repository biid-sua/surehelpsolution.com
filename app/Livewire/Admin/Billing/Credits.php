<?php

namespace App\Livewire\Admin\Billing;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\CreditEntry;
use App\Models\CreditPurchase;
use App\Models\Organization;
use App\Services\Billing\AiCredits;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * AI credits (D100), a tab of Admin › Billing: the packs on sale, credits given to a business by hand
 * (a gift or a refund), and recent purchases and use across all businesses.
 */
class Credits extends Component
{
    use PlatformAdminOnly;

    /** @var list<array{credits: string, price: string}> */
    public array $packs = [];

    /** @var array{organization_id: string, credits: string, note: string} */
    public array $grant = ['organization_id' => '', 'credits' => '', 'note' => ''];

    public function mount(AiCredits $credits): void
    {
        $this->authorize('billing.view');
        $this->fillPacks($credits);
    }

    public function addPack(): void
    {
        if (count($this->packs) < (int) config('credits.max_packs', 6)) {
            $this->packs[] = ['credits' => '', 'price' => ''];
        }
    }

    public function removePack(int $index): void
    {
        unset($this->packs[$index]);
        $this->packs = array_values($this->packs);
    }

    public function savePacks(AiCredits $credits): void
    {
        $this->authorize('billing.manage');
        $this->resetValidation();
        $rows = [];
        foreach ($this->packs as $i => $pack) {
            if (blank($pack['credits']) && blank($pack['price'])) {
                continue;
            }
            try {
                $rows[] = ['credits' => (int) $pack['credits'], 'price_cents' => Money::parse((string) $pack['price'])];
            } catch (\InvalidArgumentException) {
                $this->addError("packs.$i.price", 'Enter a price like 40 or 40.00.');

                return;
            }
        }

        try {
            $credits->savePacks($rows, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('packs', (string) collect($e->errors())->flatten()->first());

            return;
        }
        $this->fillPacks($credits);
        $this->dispatch('toast', type: 'success', message: 'Credit packs saved. Businesses see them under Billing.');
    }

    public function give(AiCredits $credits): void
    {
        $this->authorize('billing.manage');
        $this->resetValidation();
        $this->validate(['grant.organization_id' => ['required', 'integer'], 'grant.credits' => ['required', 'integer']], [], ['grant.organization_id' => 'business', 'grant.credits' => 'credits']);
        $organization = Organization::query()->findOrFail((int) $this->grant['organization_id']);

        try {
            $credits->grant($organization, (int) $this->grant['credits'], $this->grant['note'], auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('grant.'.$field, (string) $messages[0]);
            }

            return;
        }
        $this->grant = ['organization_id' => '', 'credits' => '', 'note' => ''];
        $this->dispatch('toast', type: 'success', message: 'Credits updated for '.$organization->name.'.');
    }

    private function fillPacks(AiCredits $credits): void
    {
        $this->packs = array_map(fn (array $p) => ['credits' => (string) $p['credits'], 'price' => Money::toInput($p['price_cents'])], $credits->packs());
    }

    public function render(AiCredits $credits): View
    {
        $start = now()->startOfMonth();

        return view('livewire.admin.billing.credits', [
            'canManage' => auth()->user()->can('billing.manage'),
            'businesses' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'entries' => CreditEntry::withoutGlobalScopes()->with('user:id,name')->latest('id')->limit(15)->get(),
            'names' => Organization::query()->pluck('name', 'id'),
            'pending' => CreditPurchase::withoutGlobalScopes()->where('status', 'pending')->with('invoice:id,number')->latest('id')->limit(10)->get(),
            'month' => [
                'sold_cents' => (int) CreditPurchase::withoutGlobalScopes()->where('status', 'granted')->where('granted_at', '>=', $start)->sum('price_cents'),
                'sold' => (int) CreditPurchase::withoutGlobalScopes()->where('status', 'granted')->where('granted_at', '>=', $start)->sum('credits'),
                'used' => (int) -CreditEntry::withoutGlobalScopes()->where('kind', 'usage')->where('created_at', '>=', $start)->sum('credits'),
                'outstanding' => (int) DB::table('credit_entries')->sum('credits'),
            ],
            'tokensPerCredit' => $credits->tokensPerCredit(),
        ]);
    }
}
