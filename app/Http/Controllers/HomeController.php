<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Support\Facades\Schema;

class HomeController extends Controller
{
    /**
     * Display the landing page. Its pricing section lists the plans from Admin › Billing › Plans (D98).
     */
    public function index()
    {
        $plans = rescue(fn () => Schema::hasTable('plans') ? Plan::query()->onWebsite()->ordered()->get() : collect(), collect(), report: false);

        return view('home', ['plans' => $plans]);
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
