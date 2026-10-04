<?php

namespace App\Enums;

/**
 * Why something needs the business's attention (spec §25).
 */
enum EscalationType: string
{
    case UrgentIssue = 'urgent_issue';
    case Emergency = 'emergency';
    case Complaint = 'complaint';
    case RefundRequest = 'refund_request';
    case PricingApproval = 'pricing_approval';
    case OwnerDecision = 'owner_decision';
    case TechnicalProblem = 'technical_problem';
    case AiUncertainty = 'ai_uncertainty';

    public function label(): string
    {
        return match ($this) {
            self::UrgentIssue => 'Urgent customer issue',
            self::Emergency => 'Emergency',
            self::Complaint => 'Complaint',
            self::RefundRequest => 'Refund request',
            self::PricingApproval => 'Pricing approval',
            self::OwnerDecision => 'Needs your decision',
            self::TechnicalProblem => 'Technical problem',
            self::AiUncertainty => 'AI needs a human',
        };
    }

    /** Priority used when the person raising it doesn't choose one. */
    public function defaultPriority(): EscalationPriority
    {
        return match ($this) {
            self::Emergency, self::UrgentIssue => EscalationPriority::Urgent,
            self::Complaint, self::RefundRequest, self::TechnicalProblem => EscalationPriority::High,
            default => EscalationPriority::Normal,
        };
    }
}
