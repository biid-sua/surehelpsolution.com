<?php

namespace App\Enums;

/**
 * Notification events (spec §27, §67). Only events whose trigger exists are
 * "available"; the rest are listed so mobile push and later phases share names.
 */
enum NotificationEvent: string
{
    case CallLogged = 'call.logged';
    case CallMissed = 'call.missed';
    case FollowUpCreated = 'followup.created';
    case FollowUpOverdue = 'followup.overdue';
    case TaskAssigned = 'task.assigned';
    case AppointmentCreated = 'appointment.created';
    case AppointmentUpdated = 'appointment.updated';
    case AppointmentCancelled = 'appointment.cancelled';
    case MessageReceived = 'message.received';
    case AssignmentStarted = 'assignment.started';
    case AssignmentChanged = 'assignment.changed';
    case EscalationCreated = 'escalation.created';
    case PaymentFailed = 'payment.failed';
    case InvoiceIssued = 'invoice.issued';
    case PaymentReceived = 'payment.received';
    case SubscriptionUpdated = 'subscription.updated';
    case IntegrationDisconnected = 'integration.disconnected';
    case AiEscalation = 'ai.escalation';
    case MonthlyReport = 'report.monthly';
    case UsageAlert = 'billing.usage';
    case SocialApprovalRequested = 'social.approval_requested';
    case SocialPostFailed = 'social.post_failed';
    case SocialAccountDisconnected = 'social.account_disconnected';

    /**
     * @return list<self>
     */
    public static function available(): array
    {
        return [self::CallLogged, self::CallMissed, self::FollowUpCreated, self::FollowUpOverdue, self::TaskAssigned, self::EscalationCreated, self::AppointmentCreated, self::AppointmentUpdated, self::AppointmentCancelled, self::IntegrationDisconnected, self::InvoiceIssued, self::PaymentReceived, self::PaymentFailed, self::UsageAlert, self::MonthlyReport, self::SocialApprovalRequested, self::SocialPostFailed, self::SocialAccountDisconnected, self::MessageReceived, self::AssignmentStarted, self::AssignmentChanged];
    }

    public function isAvailable(): bool
    {
        return in_array($this, self::available(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::CallLogged => 'New call handled',
            self::CallMissed => 'Missed or dropped call',
            self::FollowUpCreated => 'Caller asked for a call back',
            self::FollowUpOverdue => 'Follow-up overdue',
            self::TaskAssigned => 'Task assigned to you',
            self::AppointmentCreated => 'Appointment booked',
            self::AppointmentUpdated => 'Appointment changed',
            self::AppointmentCancelled => 'Appointment cancelled',
            self::MessageReceived => 'New message',
            self::EscalationCreated => 'Escalation raised',
            self::PaymentFailed => 'Payment overdue',
            self::InvoiceIssued => 'New invoice',
            self::PaymentReceived => 'Payment received',
            self::SubscriptionUpdated => 'Subscription changed',
            self::IntegrationDisconnected => 'Integration disconnected',
            self::AiEscalation => 'AI needs your attention',
            self::MonthlyReport => 'Monthly results report',
            self::UsageAlert => 'Plan usage',
            self::AssignmentStarted => 'Assigned to a company',
            self::AssignmentChanged => 'Company assignment changed',
            self::SocialApprovalRequested => 'Social post waiting for approval',
            self::SocialPostFailed => 'Social post didn\'t publish',
            self::SocialAccountDisconnected => 'Social account needs reconnecting',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CallLogged => 'Every call our team answers for you, with the outcome.',
            self::CallMissed => 'A caller hung up or couldn\'t be reached.',
            self::FollowUpCreated => 'Someone is waiting for you to call them back.',
            self::FollowUpOverdue => 'A call-back or task is past its due time.',
            self::TaskAssigned => 'A teammate gave you a task.',
            self::AppointmentCreated => 'Someone booked an appointment with you.',
            self::IntegrationDisconnected => 'A connected calendar stopped syncing and needs reconnecting.',
            self::InvoiceIssued => 'Your SureHelp invoice, with a link to pay.',
            self::PaymentReceived => 'A receipt when we receive your payment.',
            self::PaymentFailed => 'An invoice is past its due date.',
            self::AppointmentUpdated => 'An appointment was moved to a new time.',
            self::AppointmentCancelled => 'An appointment was cancelled.',
            self::MonthlyReport => 'On the 1st: last month\'s calls answered, leads, bookings and estimated revenue, with a PDF.',
            self::UsageAlert => 'When you\'ve used 80% and 100% of the calls your plan includes.',
            self::EscalationCreated => 'Something needs you now. Urgent escalations always reach you in the app and by email.',
            self::SocialApprovalRequested => 'A post written by your team or ours is ready for you to approve.',
            self::MessageReceived => 'A customer wrote to you and a person needs to answer (not sent while your AI assistant is handling it).',
            self::AssignmentStarted => 'You start serving a company, now or on a planned date.',
            self::AssignmentChanged => 'An assignment of yours ended, was paused or was scheduled.',
            self::SocialPostFailed => 'A scheduled post couldn\'t be published to one or more accounts.',
            self::SocialAccountDisconnected => 'We lost access to a connected social account.',
            default => '',
        };
    }

    /**
     * Channels used until the user chooses their own (NTF-02).
     *
     * @return list<string>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::CallLogged => ['database'],
            default => ['database', 'mail'],
        };
    }

    /**
     * Who can receive it: settings only offer events a person can actually get.
     */
    public function requiredPermission(): ?string
    {
        return match ($this) {
            self::InvoiceIssued, self::PaymentReceived, self::PaymentFailed, self::SubscriptionUpdated, self::UsageAlert => 'billing.view',
            self::IntegrationDisconnected => 'integrations.manage',
            self::MonthlyReport => 'reports.view',
            self::EscalationCreated => 'escalations.view',
            self::TaskAssigned, self::FollowUpOverdue => 'tasks.view',
            self::AppointmentCreated, self::AppointmentUpdated, self::AppointmentCancelled => 'appointments.view',
            self::SocialApprovalRequested, self::SocialPostFailed, self::SocialAccountDisconnected => 'social.manage',
            self::MessageReceived => 'messages.view',
            self::AssignmentStarted, self::AssignmentChanged => 'agent_university.view', // agents and supervisors only
            default => null,
        };
    }
}
