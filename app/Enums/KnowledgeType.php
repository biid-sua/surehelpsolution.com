<?php

namespace App\Enums;

/**
 * What a knowledge item is (spec §22). The same items brief agents now and the AI later.
 */
enum KnowledgeType: string
{
    case Faq = 'faq';
    case Policy = 'policy';
    case ServiceInfo = 'service_info';
    case PricingGuidance = 'pricing_guidance';
    case Procedure = 'procedure';
    case AgentInstruction = 'agent_instruction';
    case EmergencyInstruction = 'emergency_instruction';
    case EscalationRule = 'escalation_rule';

    public function label(): string
    {
        return match ($this) {
            self::Faq => 'FAQ',
            self::Policy => 'Policy',
            self::ServiceInfo => 'Service information',
            self::PricingGuidance => 'Pricing guidance',
            self::Procedure => 'How we do things',
            self::AgentInstruction => 'Instruction for agents',
            self::EmergencyInstruction => 'Emergency instruction',
            self::EscalationRule => 'When to escalate',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Faq => 'chat',
            self::EmergencyInstruction, self::EscalationRule => 'alert',
            self::PricingGuidance => 'chart',
            self::AgentInstruction => 'phone',
            default => 'info',
        };
    }
}
