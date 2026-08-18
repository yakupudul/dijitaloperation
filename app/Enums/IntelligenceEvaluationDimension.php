<?php

namespace App\Enums;

/**
 * Independent evaluation dimensions — never collapsed into one AI score.
 */
enum IntelligenceEvaluationDimension: string
{
    case Safety = 'safety';
    case Retrieval = 'retrieval';
    case Grounding = 'grounding';
    case CurrentTruth = 'current_truth';
    case Abstention = 'abstention';
    case Specificity = 'specificity';
    case Genericity = 'genericity';
    case Usefulness = 'usefulness';
    case Efficiency = 'efficiency';
    case Regression = 'regression';
}
