<?php

namespace App\Services\Sites;

use App\Models\BusinessLocation;
use App\Models\BusinessProfile;
use App\Models\BusinessService;
use App\Models\ChatWidget;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Site;
use App\Models\SiteVersion;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a business's hosted website from a template and what the business already told us
 * (D99), and keeps its versions: publish, restore an older one, or drop unpublished changes. Only
 * facts we hold go on the site; nothing is invented.
 */
class SiteBuilder
{
    public function __construct(private readonly Audit $audit) {}

    public function suggestSubdomain(Organization $organization): string
    {
        $base = Str::limit(Str::slug($organization->name), 30, '') ?: 'site';
        $base = in_array($base, (array) config('sites.reserved'), true) ? $base.'-site' : $base;
        $candidate = $base;
        for ($n = 2; Site::withoutGlobalScopes()->where('subdomain', $candidate)->exists(); $n++) {
            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }

    /** @throws ValidationException */
    public function checkSubdomain(string $subdomain, ?Site $ignore = null): string
    {
        $subdomain = Str::lower(trim($subdomain));
        $message = match (true) {
            ! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', $subdomain) => 'Use 3 to 40 letters, numbers and dashes, starting and ending with a letter or number.',
            in_array($subdomain, (array) config('sites.reserved'), true) => 'That address is reserved. Please choose another.',
            Site::withoutGlobalScopes()->where('subdomain', $subdomain)->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))->exists() => 'That address is taken. Please choose another.',
            default => null,
        };
        if ($message) {
            throw ValidationException::withMessages(['subdomain' => $message]);
        }

        return $subdomain;
    }

    /** @throws ValidationException */
    public function create(Organization $organization, string $template, string $subdomain, User $by): Site
    {
        if (! array_key_exists($template, (array) config('sites.templates'))) {
            throw ValidationException::withMessages(['template' => 'Choose one of the templates.']);
        }
        if (Site::withoutGlobalScopes()->where('organization_id', $organization->id)->exists()) {
            throw ValidationException::withMessages(['template' => 'Your business already has a website.']);
        }
        $subdomain = $this->checkSubdomain($subdomain);

        return DB::transaction(function () use ($organization, $template, $subdomain, $by) {
            $site = Site::create(['organization_id' => $organization->id, 'subdomain' => $subdomain, 'template' => $template]);
            $this->addVersion($site, $this->starter($organization, $template), 'Created from the '.config("sites.templates.$template.label").' template', 'template', $by);
            $this->audit->record('site.created', $site, new: ['template' => $template, 'subdomain' => $subdomain], organization: $organization, actor: $by, label: $subdomain);

            return $site;
        });
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function addVersion(Site $site, array $content, string $note, string $source, ?User $by): SiteVersion
    {
        return DB::transaction(function () use ($site, $content, $note, $source, $by) {
            $number = (int) SiteVersion::withoutGlobalScopes()->where('site_id', $site->id)->lockForUpdate()->max('number') + 1;

            return SiteVersion::create([
                'site_id' => $site->id, 'organization_id' => $site->organization_id, 'number' => $number,
                'content' => SiteContent::normalize($content), 'note' => Str::limit($note, 200, ''), 'source' => $source, 'created_by_user_id' => $by?->id,
            ]);
        });
    }

    public function publish(Site $site, User $by): SiteVersion
    {
        $draft = $site->draft();
        $site->forceFill(['published_version_id' => $draft->id, 'published_at' => now()])->save();
        $this->audit->record('site.published', $site, new: ['version' => $draft->number], organization: $site->organization, actor: $by, label: $site->subdomain);

        return $draft;
    }

    public function unpublish(Site $site, User $by): void
    {
        $site->forceFill(['published_version_id' => null, 'published_at' => null])->save();
        $this->audit->record('site.unpublished', $site, organization: $site->organization, actor: $by, label: $site->subdomain);
    }

    /** An older version becomes the newest draft again (undo); history is kept. */
    public function restore(Site $site, SiteVersion $version, User $by): SiteVersion
    {
        abort_unless($version->site_id === $site->id, 404);
        $restored = $this->addVersion($site, $version->content, 'Went back to version '.$version->number, 'restore', $by);
        $this->audit->record('site.restored', $site, new: ['version' => $version->number], organization: $site->organization, actor: $by, label: $site->subdomain);

        return $restored;
    }

    /**
     * The template filled with the business's own details.
     *
     * @return array<string, mixed>
     */
    public function starter(Organization $organization, string $template): array
    {
        $config = (array) config("sites.templates.$template");
        $profile = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
        $name = $profile?->display_name ?: $organization->name;
        $location = BusinessLocation::withoutGlobalScopes()->where('organization_id', $organization->id)->orderByDesc('is_primary')->first();
        $town = $location?->city ? trim($location->city.($location->state ? ', '.$location->state : '')) : null;
        $area = $profile?->service_area ?: $town;
        $widget = ChatWidget::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
        $books = (bool) ($widget?->is_enabled && $widget->offers('booking'));
        $button = $books ? 'Book online' : 'Call us now';
        $services = BusinessService::withoutGlobalScopes()->where('organization_id', $organization->id)->where('is_active', true)
            ->orderBy('sort_order')->limit(9)->get();
        $reviews = Review::withoutGlobalScopes()->where('organization_id', $organization->id)->where('rating', '>=', 4)
            ->whereNotNull('comment')->latest('reviewed_at')->limit(3)->get();

        $sections = [];
        foreach ((array) $config['sections'] as $i => $type) {
            $section = match ($type) {
                'hero' => ['heading' => $name, 'button' => $button,
                    'text' => $profile?->description ? Str::limit($profile->description, 220) : ($area ? "Friendly, reliable service in {$area}. A real person answers your call, day or night." : 'Friendly, reliable service. A real person answers your call, day or night.')],
                'services' => $services->isEmpty() ? null : ['heading' => 'Our services', 'items' => $services->map(fn (BusinessService $s) => ['title' => $s->name, 'text' => (string) $s->description])->all()],
                'text' => $profile?->description ? ['heading' => 'About '.$name, 'text' => $profile->description] : null,
                'reviews' => $reviews->isEmpty() ? null : ['heading' => 'What our customers say',
                    'items' => $reviews->map(fn (Review $r) => ['title' => Str::of((string) $r->author_name)->before(' ')->value() ?: 'A customer', 'text' => Str::limit((string) $r->comment, 400)])->all()],
                'area' => $area ? ['heading' => 'Where we work', 'text' => "We serve {$area} and nearby."] : null,
                'hours' => ['heading' => 'Opening hours', 'text' => 'Our phones are answered 24/7, even when we\'re closed.'],
                'faq' => ['heading' => 'Questions', 'items' => array_values(array_filter([
                    ['title' => 'Can I call outside business hours?', 'text' => 'Yes. A real person answers 24/7, takes your details and makes sure we get back to you.'],
                    $books ? ['title' => 'Can I book online?', 'text' => 'Yes. Press "Book online" to pick a time that suits you.'] : null,
                    $area ? ['title' => 'Which areas do you cover?', 'text' => "We serve {$area} and nearby. Call us if you're not sure."] : null,
                ]))],
                'contact' => ['heading' => 'Get in touch', 'text' => ($books ? 'Call, email or book online.' : 'Call or email us.').' We\'ll get back to you quickly.'],
                'cta' => ['heading' => 'Ready when you are', 'text' => ($books ? 'Call now or book online.' : 'Call now.').' A real person answers 24/7.', 'button' => $button],
                default => null,
            };
            if ($section !== null) {
                $sections[] = ['id' => $type.'-'.($i + 1), 'type' => $type] + $section + ['heading' => '', 'text' => '', 'button' => '', 'items' => []];
            }
        }

        $accent = $profile?->brand_color && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $profile->brand_color) ? $profile->brand_color : $config['accent'];

        return [
            'theme' => ['accent' => $accent, 'style' => $config['style']],
            'pages' => [[
                'slug' => '', 'title' => 'Home',
                'seo_title' => Str::limit($name.($area ? ' | '.$area : ''), 70, ''),
                'seo_description' => Str::limit($profile?->description ?: "{$name}: call or book online. A real person answers 24/7.", 160, ''),
                'sections' => $sections,
            ]],
        ];
    }
}
