<?php

namespace App\Services\Support;

use App\Actions\Notifications\NotifyOrganization;
use App\Enums\SupportTicketStatus;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\SupportTicketActivity;
use App\Support\Audit\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Support requests between a business and SureHelp (spec §78, CLI-09, D49).
 *
 * - A business opens a request; SureHelp staff with support.manage are told (the assignee, once there is one).
 * - Staff replies go back to the business's people who can see support, and mark it "waiting for you".
 * - A business reply reopens a request that was waiting or resolved (in progress once someone has it); a closed one stays closed.
 * Attachments are stored privately and only downloaded through an authorized route.
 */
class SupportDesk
{
    public const ATTACHMENT_RULES = ['nullable', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,gif,webp,txt,csv,doc,docx,xls,xlsx'];

    public function __construct(
        private readonly Audit $audit,
        private readonly NotifyOrganization $notifyBusiness,
    ) {}

    /**
     * @param  array{subject: string, category: string, priority: string, body: string}  $data
     */
    public function open(Organization $organization, User $by, array $data, ?UploadedFile $file = null): SupportTicket
    {
        $ticket = DB::transaction(function () use ($organization, $by, $data, $file) {
            $ticket = SupportTicket::create([
                'organization_id' => $organization->id,
                'opened_by_user_id' => $by->id,
                'subject' => trim($data['subject']),
                'category' => $data['category'],
                'priority' => $data['priority'],
                'status' => SupportTicketStatus::Open,
                'last_reply_at' => now(),
            ]);
            $this->addMessage($ticket, $by, $data['body'], false, $file);

            return $ticket;
        });

        $this->audit->record('support.opened', $ticket, new: ['subject' => $ticket->subject, 'category' => $ticket->category, 'priority' => $ticket->priority], organization: $organization, actor: $by, label: $ticket->reference());
        $this->tellStaff($ticket, 'New request from '.$organization->name.': '.$ticket->subject);

        return $ticket;
    }

    /**
     * @throws ValidationException when the request is closed
     */
    public function reply(SupportTicket $ticket, User $by, string $body, bool $asStaff, ?UploadedFile $file = null, ?SupportTicketStatus $status = null): SupportTicketMessage
    {
        if ($ticket->status === SupportTicketStatus::Closed && ! $asStaff) {
            throw ValidationException::withMessages(['reply' => ['This request is closed. Please open a new one.']]);
        }

        $message = DB::transaction(function () use ($ticket, $by, $body, $asStaff, $file, $status) {
            $message = $this->addMessage($ticket, $by, $body, $asStaff, $file);
            $next = $asStaff
                ? ($status ?? SupportTicketStatus::WaitingForCustomer)
                : ($ticket->assigned_to_user_id ? SupportTicketStatus::InProgress : SupportTicketStatus::Open);
            $ticket->forceFill(['last_reply_at' => now(), 'last_reply_by_staff' => $asStaff])->save();
            $this->applyStatus($ticket, $next);

            return $message;
        });

        if ($asStaff) {
            $this->tellBusiness($ticket, 'SureHelp replied to '.$ticket->reference().': '.$ticket->subject, $by);
        } else {
            $this->tellStaff($ticket, $ticket->organization->name.' replied to '.$ticket->reference().': '.$ticket->subject);
        }

        return $message;
    }

    public function setStatus(SupportTicket $ticket, SupportTicketStatus $status, User $by): void
    {
        $old = $ticket->status;
        if ($old === $status) {
            return;
        }
        $this->applyStatus($ticket, $status);
        $this->audit->record('support.status_changed', $ticket, old: ['status' => $old->value], new: ['status' => $status->value], actor: $by, label: $ticket->reference());

        // Staff resolving or closing tells the business; a business closing its own request tells staff.
        if ($by->isAdmin()) {
            if (in_array($status, [SupportTicketStatus::Resolved, SupportTicketStatus::Closed], true)) {
                $this->tellBusiness($ticket, $ticket->reference().' is '.mb_strtolower($status->label()).': '.$ticket->subject, $by);
            }
        } else {
            $this->tellStaff($ticket, $ticket->organization->name.' marked '.$ticket->reference().' '.mb_strtolower($status->label()));
        }
    }

    public function assign(SupportTicket $ticket, ?User $to, User $by): void
    {
        abort_if($to && ! $to->hasPermissionIn('support.manage'), 422);
        $ticket->forceFill(['assigned_to_user_id' => $to?->id])->save();
        if ($to && $ticket->status === SupportTicketStatus::Open) {
            $this->applyStatus($ticket, SupportTicketStatus::InProgress);
        }
        $this->audit->record('support.assigned', $ticket, new: ['assigned_to' => $to?->name], actor: $by, label: $ticket->reference());
    }

    /** @return Collection<int, User> SureHelp staff who answer support */
    public function staff(): Collection
    {
        return User::query()->where('role', 'admin')->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->hasPermissionIn('support.manage'))->values();
    }

    private function applyStatus(SupportTicket $ticket, SupportTicketStatus $status): void
    {
        $ticket->forceFill([
            'status' => $status,
            'resolved_at' => $status === SupportTicketStatus::Resolved ? now() : ($status->isActive() ? null : $ticket->resolved_at),
            'closed_at' => $status === SupportTicketStatus::Closed ? now() : null,
        ])->save();
    }

    private function addMessage(SupportTicket $ticket, User $by, string $body, bool $asStaff, ?UploadedFile $file): SupportTicketMessage
    {
        $path = $file?->store('support/'.$ticket->organization_id, 'local');

        return SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'organization_id' => $ticket->organization_id,
            'user_id' => $by->id,
            'is_staff' => $asStaff,
            'body' => trim($body),
            'attachment_path' => $path ?: null,
            'attachment_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 200) : null,
            'attachment_size' => $file?->getSize(),
        ]);
    }

    private function tellStaff(SupportTicket $ticket, string $title): void
    {
        $to = $ticket->assignee && $ticket->assignee->is_active ? collect([$ticket->assignee]) : $this->staff();
        Notification::send($to, new SupportTicketActivity($ticket, $title, staff: true));
    }

    private function tellBusiness(SupportTicket $ticket, string $title, User $by): void
    {
        $this->notifyBusiness->handle($ticket->organization, new SupportTicketActivity($ticket, $title, staff: false), 'support.view', $by);
    }
}
