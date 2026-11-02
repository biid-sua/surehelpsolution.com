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
        $previewing = $designs->previewing();

        return $previewing
            ? $this->home($designs->view($previewing), preview: true)->header('X-Robots-Tag', 'noindex, nofollow')
            : $this->home($designs->view($designs->current()));
    }

    /**
     * A design shown through a signed preview link before it goes live. The design stays on for the
     * rest of the visit, so the other public pages can be checked in it too; kept out of search results.
     */
    public function preview(SiteDesigns $designs, string $design)
    {
        abort_unless($designs->exists($design), 404);
        $designs->startPreview($design);

        return redirect()->route('home');
    }

    /** Leave a preview and go back to the live design. */
    public function endPreview(SiteDesigns $designs)
    {
        $designs->endPreview();

        return redirect()->route('home');
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
