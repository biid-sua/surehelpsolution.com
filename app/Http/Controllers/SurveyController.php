<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Survey;
use App\Services\Business\Branding;
use App\Services\Surveys\SurveyDesk;
use App\Support\CustomerLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The page a customer opens from a satisfaction survey (D84). The link is the only key: it holds no
 * personal details, works for 7 days, and a GET never records anything (link scanners open links too).
 */
class SurveyController extends Controller
{
    public function show(Request $request, string $token, Branding $branding): View
    {
        $survey = $this->find($token);
        CustomerLocale::apply($survey->organization_id);   // D92
        $preset = (int) $request->query('score');

        return view('survey.show', [
            'survey' => $survey,
            'brand' => $branding->for(Organization::query()->findOrFail($survey->organization_id)),
            'preset' => $survey->answered_at === null && $preset >= 1 && $preset <= 5 ? $preset : null,
            'step' => match (true) {
                ! $survey->isOpen() && $survey->answered_at === null => 'closed',
                $survey->answered_at === null => 'ask',
                $survey->score >= Survey::HAPPY => 'happy',
                $survey->feedback === null && $survey->isOpen() => 'sorry',
                default => 'thanks',
            },
        ]);
    }

    public function answer(Request $request, string $token, SurveyDesk $desk): RedirectResponse
    {
        $survey = $this->find($token);
        $score = (int) $request->validate(['score' => ['required', 'integer', 'between:1,5']])['score'];
        $desk->answer($survey, $score, 'link');

        return redirect()->route('survey.show', $token);
    }

    public function feedback(Request $request, string $token, SurveyDesk $desk): RedirectResponse
    {
        $survey = $this->find($token);
        $desk->feedback($survey, (string) $request->validate(['feedback' => ['required', 'string', 'max:2000']])['feedback']);

        return redirect()->route('survey.show', $token);
    }

    public function review(string $token): RedirectResponse
    {
        $survey = $this->find($token);
        abort_unless($survey->review_url && $survey->score >= Survey::HAPPY, 404);
        $survey->forceFill(['review_clicked_at' => $survey->review_clicked_at ?? now()])->save();

        return redirect()->away($survey->review_url);
    }

    private function find(string $token): Survey
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{32}$/', $token) === 1, 404);

        return Survey::withoutGlobalScopes()->with('customer')->where('token', $token)->firstOrFail();
    }
}
