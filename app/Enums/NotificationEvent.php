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
    case EscalationCreated = 'escalation.created';
    case PaymentFailed = 'payment.failed';
    case SubscriptionUpdated = 'subscription.updated';
    case IntegrationDisconnected = 'integration.disconnected';
    case AiEscalation = 'ai.escalation';

    /**
     * @return list<self>
     */
    public static function available(): array
    {
        return [self::CallLogged, self::CallMissed, self::FollowUpCreated, self::FollowUpOverdue, self::TaskAssigned, self::EscalationCreated, self::AppointmentCreated, self::AppointmentUpdated, self::AppointmentCancelled];
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
            self::PaymentFailed => 'Payment failed',
            self::SubscriptionUpdated => 'Subscription changed',
            self::IntegrationDisconnected => 'Integration disconnected',
            self::AiEscalation => 'AI needs your attention',
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
            self::AppointmentUpdated => 'An appointment was moved to a new time.',
            self::AppointmentCancelled => 'An appointment was cancelled.',
            self::EscalationCreated => 'Something needs you now. Urgent escalations always reach you in the app and by email.',
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
}
