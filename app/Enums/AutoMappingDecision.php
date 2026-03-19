<?php

namespace App\Enums;

enum AutoMappingDecision: string
{
    case AutoApplied = 'auto_applied';
    case ManualRequired = 'manual_required';
    case Rejected = 'rejected';
    case ManualOverride = 'manual_override';
}
