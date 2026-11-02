<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Support\Ui\SiteDesigns;
use Illuminate\Support\Facades\Schema;

class HomeController extends Controller
{
    /**
     * Display the landing page in the design chosen in Admin › Appearance (D121). Its pricing section
     * lists the plans from Admin › Billing › Plans (D98).
     */
    public function index(SiteDesigns $designs)
    {
        return $this->home($designs->view($designs->current()));
    }

    /**
     * A design shown through a signed preview link before it goes live, kept out of search results.
     */
    public function preview(SiteDesigns $designs, string $design)
    {
        abort_unless($designs->exists($design), 404);

        return $this->home($designs->view($design), preview: true)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function home(string $view, bool $preview = false)
    {
        $plans = rescue(fn () => Schema::hasTable('plans') ? Plan::query()->onWebsite()->ordered()->get() : collect(), collect(), report: false);

        return response()->view($view, ['plans' => $plans, 'preview' => $preview]);
    }

    /**
     * Display the Terms of Use page.
     */
    public function termsOfUse()
    {
        return view('legal.terms-of-use');
    }

    /**
     * Display the Privacy Policy page.
     */
    public function privacyPolicy()
    {
        return view('legal.privacy-policy');
    }

    /**
     * Display the Data Processing Addendum page.
     */
    public function dataProcessingAddendum()
    {
        return view('legal.data-processing-addendum');
    }

    /**
     * Display the Business Associate Agreement page.
     */
    public function businessAssociateAgreement()
    {
        return view('legal.business-associate-agreement');
    }

    /**
     * Display the Data Security page.
     */
    public function dataSecurity()
    {
        return view('legal.data-security');
    }

    /**
     * Display the Cookie Notice page.
     */
    public function cookieNotice()
    {
        return view('legal.cookie-notice');
    }

    /**
     * Display the FAQ page.
     */
    public function faq()
    {
        return view('legal.faq');
    }
}
