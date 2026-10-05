<?php

namespace App\Livewire\Client\Social;

use App\Actions\Social\StoreMedia;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\MediaAsset;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * The business's photos for posts (spec §41): upload, describe (alt text), delete.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Media library')]
class Media extends Component
{
    use ScopedToOrganization, WithFileUploads, WithPagination;

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

    /** @var array<string, string> ulid => alt text being edited */
    public array $alt = [];

    public function mount(): void
    {
        $this->authorize('social.view', $this->organization());
    }

    public function updatedUploads(StoreMedia $store): void
    {
        $organization = $this->organization();
        $this->authorize('social.manage', $organization);
        $this->validate(...self::uploadRules('uploads'));

        foreach ($this->uploads as $file) {
            $store->handle($organization, $file, auth()->user());
        }

        $count = count($this->uploads);
        $this->reset('uploads');
        $this->resetPage();
        $this->dispatch('toast', type: 'success', message: $count.' '.str('photo')->plural($count).' added. Add a short description to each for people using screen readers.');
    }

    /**
     * Shared by the library and the composer.
     *
     * @return array{0: array<string, list<string>>, 1: array<string, string>}
     */
    public static function uploadRules(string $field): array
    {
        $mimes = implode(',', (array) config('social.media.mimes'));

        return [
            [$field => ['array', 'max:10'], $field.'.*' => ['image', 'mimes:'.$mimes, 'max:'.(int) config('social.media.max_kb')]],
            [
                $field.'.max' => 'Add up to 10 photos at a time.',
                $field.'.*.image' => 'Only photos can be added (JPG, PNG or WebP).',
                $field.'.*.mimes' => 'Only JPG, PNG or WebP photos can be added.',
                $field.'.*.max' => 'Each photo must be under '.round((int) config('social.media.max_kb') / 1024).' MB.',
            ],
        ];
    }

    public function saveAlt(string $ulid, Audit $audit): void
    {
        $this->authorize('social.manage', $this->organization());
        $this->validate(['alt.'.$ulid => ['nullable', 'string', 'max:500']]);

        $asset = $this->asset($ulid);
        $asset->alt_text = filled($this->alt[$ulid] ?? null) ? trim($this->alt[$ulid]) : null;
        $asset->save();
        $audit->changes('media.updated', $asset, ['alt_text']);
        $this->dispatch('toast', type: 'success', message: 'Description saved.');
    }

    public function delete(string $ulid, Audit $audit): void
    {
        $this->authorize('social.manage', $this->organization());
        $asset = $this->asset($ulid);

        $inUse = DB::table('social_post_media')->join('social_posts', 'social_posts.id', '=', 'social_post_media.social_post_id')
            ->where('social_post_media.media_asset_id', $asset->id)
            ->whereIn('social_posts.status', ['draft', 'in_review', 'scheduled', 'publishing'])->exists();

        if ($inUse) {
            $this->dispatch('toast', type: 'error', message: 'A post that hasn\'t gone out yet uses this photo. Remove it from that post first.');

            return;
        }

        $audit->record('media.deleted', $asset, old: ['name' => $asset->original_name], organization: $this->organization(), label: $asset->original_name);
        $asset->delete();
    }

    private function asset(string $ulid): MediaAsset
    {
        return MediaAsset::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    public function render(): View
    {
        $assets = MediaAsset::query()->forOrganization($this->organization())->latest('id')->paginate(24);

        foreach ($assets as $asset) {
            $this->alt[$asset->ulid] ??= (string) $asset->alt_text;
        }

        return view('livewire.client.social.media', [
            'assets' => $assets,
            'canManage' => auth()->user()->can('social.manage', $this->organization()),
        ]);
    }
}
