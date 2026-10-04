<?php

namespace App\Enums;

/**
 * Everything that can appear on a customer's timeline (spec §13).
 * Later phases (appointments, messaging, AI) add their entries with these names.
 */
enum TimelineEventType: string
{
    case CustomerCreated = 'customer_created';
    case CallIncoming = 'call_incoming';
    case CallOutgoing = 'call_outgoing';
    case AppointmentCreated = 'appointment_created';
    case AppointmentUpdated = 'appointment_updated';
    case AppointmentCancelled = 'appointment_cancelled';
    case SmsSent = 'sms_sent';
    case EmailSent = 'email_sent';
    case MessageReceived = 'message_received';
    case NoteAdded = 'note_added';
    case TaskCreated = 'task_created';
    case TaskCompleted = 'task_completed';
    case ReviewRequest = 'review_request';
    case AiConversation = 'ai_conversation';
    case Escalation = 'escalation';

    public function icon(): string
    {
        return match ($this) {
            self::CallIncoming, self::CallOutgoing => 'phone',
            self::AppointmentCreated, self::AppointmentUpdated, self::AppointmentCancelled => 'calendar',
            self::SmsSent, self::EmailSent, self::MessageReceived => 'chat',
            self::NoteAdded => 'list',
            self::TaskCreated, self::TaskCompleted => 'check-circle',
            self::Escalation => 'alert',
            self::AiConversation => 'sparkles',
            default => 'user',
        };
    }
}
