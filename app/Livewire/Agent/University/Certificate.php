<?php

namespace App\Livewire\Agent\University;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\TrainingCertificate;
use App\Services\Training\TrainingAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A certificate, printable or saved as PDF from the browser (brief §1.11). Visible to its holder
 * and to the people who may see that agent's training; anyone else gets 404.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
class Certificate extends Component
{
    use AgentWorkspaceOnly;

    #[Locked]
    public int $certificateId;

    public function mount(TrainingCertificate $certificate): void
    {
        $this->certificateId = $certificate->id;
        $this->certificate();
    }

    private function certificate(): TrainingCertificate
    {
        $certificate = TrainingCertificate::query()->with('agent:id,name', 'course:id,ulid,title,organization_id', 'course.organization:id,name')->find($this->certificateId);
        $user = auth()->user();
        abort_unless($certificate && ($certificate->agent_user_id === $user->id || app(TrainingAccess::class)->canSeeAgent($user, $certificate->agent)), 404);

        return $certificate;
    }

    public function render(): View
    {
        $certificate = $this->certificate();

        return view('livewire.agent.university.certificate', ['certificate' => $certificate])->title($certificate->name);
    }
}
