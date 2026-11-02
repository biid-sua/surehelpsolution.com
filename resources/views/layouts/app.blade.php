{{-- The public website's layout: the design chosen in Admin › Appearance, or the one being previewed (D121). --}}
@include(app(\App\Support\Ui\SiteDesigns::class)->active() === 'modern' ? 'layouts.modern' : 'layouts.classic')
