<?php

namespace App\Enums;

enum BusinessOutcomeKind: string
{
    case QualifiedLead = 'qualified_lead';
    case Consultation = 'consultation';
    case SaleOrPatient = 'sale_or_patient';
    case Revenue = 'revenue';

    public function unit(): BusinessOutcomeUnit
    {
        return match ($this) {
            self::Revenue => BusinessOutcomeUnit::Money,
            default => BusinessOutcomeUnit::Count,
        };
    }

    public function defaultLabel(): string
    {
        return match ($this) {
            self::QualifiedLead => 'Qualified Lead',
            self::Consultation => 'Consultation',
            self::SaleOrPatient => 'Sale / Patient',
            self::Revenue => 'Revenue',
        };
    }

    public function defaultCode(): string
    {
        return $this->value;
    }

    public function requiresCurrency(): bool
    {
        return $this === self::Revenue;
    }
}
