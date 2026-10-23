<?php

namespace App\Services\Surveys;

use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Notifications\NotifyOrganization;
use App\Actions\Tasks\CreateTask;
use App\Enums\TaskType;
use App\Enums\TimelineEventType;
use App\Models\Organization;
use App\Models\Survey;
use App\Models\Task;
use App\Notifications\CustomerUnhappy;
use App\Services\Integrations\Webhooks;
use Carbon\CarbonInterface;

/**
 * Satisfaction surveys (D84): records answers, and makes sure an unhappy customer reaches a person.
 */
class SurveyDesk
{
    /** A survey stays open this long. */
    public const OPEN_DAYS = 7;

    /** One survey per customer at most this often. */
    public const QUIET_DAYS = 14;

    public function __construct(
        private readonly CreateTask $createTask,
        private readonly NotifyOrganization $notify,
        private readonly RecordTimelineEvent $timeline,
    ) {}

    /** Record (or change) the score. False when the survey has closed. */
    public function answer(Survey $survey, int $score, string $by): bool
    {
        if (! $survey->isOpen() || $score < 1 || $score > 5) {
            return false;
        }
        $first = $survey->answered_at === null;
        $survey->forceFill(['score' => $score, 'answered_by' => $by, 'answered_at' => $survey->answered_at ?? now()])->save();

        if ($first && $survey->customer) {
            $this->timeline->handle($survey->customer, TimelineEventType::NoteAdded, 'Rated their experience '.$score.' out of 5', null, null, ['survey' => $survey->ulid]);
        }
        if ($score < Survey::HAPPY && ! $survey->task_id) {
            $this->escalate($survey);
        }
        if ($first) {
            app(Webhooks::class)->dispatch('survey.answered', $survey);
        }

        return true;
    }

    public function feedback(Survey $survey, string $text): bool
    {
        $text = trim(mb_substr($text, 0, 2000));
        if ($text === '' || $survey->answered_at === null || $survey->expires_at->isPast() || $survey->feedback !== null) {
            return false;
        }
        $survey->forceFill(['feedback' => $text])->save();
        if ($survey->task_id && ($task = Task::withoutGlobalScopes()->find($survey->task_id))) {
            $task->forceFill(['description' => trim($task->description."\n\nWhat they said: ".$text)])->save();
        }
        if ($survey->customer) {
            $this->timeline->handle($survey->customer, TimelineEventType::NoteAdded, 'Feedback after their survey', $text, null, ['survey' => $survey->ulid]);
        }

        return true;
    }

    /** A score below 4: a task to call them, and the team hears about it. */
    private function escalate(Survey $survey): void
    {
        $organization = Organization::query()->findOrFail($survey->organization_id);
        $name = $survey->customer?->fullName() ?: 'A customer';
        $task = $this->createTask->handle($organization, [
            'type' => TaskType::Callback,
            'title' => 'Call '.$name.': rated us '.$survey->score.' out of 5',
            'description' => 'From the satisfaction survey'.($survey->agent ? ' after a call with '.$survey->agent->name : '').'.',
            'customer_id' => $survey->customer_id,
            'priority' => 'high',
            'due_at' => now()->addHours(4),
        ], null, 'survey');
        $survey->forceFill(['task_id' => $task->id])->save();
        $this->notify->handle($organization, new CustomerUnhappy($organization, $survey), 'customers.view');
    }

    /**
     * @return array{sent: int, answered: int, average: ?float, happy: ?int, review_clicks: int}
     */
    public function summary(Organization $organization, CarbonInterface $start, CarbonInterface $end, ?int $agentId = null): array
    {
        $sent = Survey::withoutGlobalScopes()->where('organization_id', $organization->id)->whereBetween('sent_at', [$start, $end])
            ->when($agentId, fn ($q) => $q->where('agent_user_id', $agentId));
        $answered = (clone $sent)->whereNotNull('score');
        $count = (clone $answered)->count();

        return [
            'sent' => (clone $sent)->count(),
            'answered' => $count,
            'average' => $count ? round((float) (clone $answered)->avg('score'), 1) : null,
            'happy' => $count ? (int) round(100 * (clone $answered)->where('score', '>=', Survey::HAPPY)->count() / $count) : null,
            'review_clicks' => (clone $sent)->whereNotNull('review_clicked_at')->count(),
        ];
    }
}
