<?php

namespace App\Support\DomainEvents;

use App\Enums\DomainEventSubjectKind;
use App\Models\BusinessOutcomeRecheckRun;
use App\Models\Finding;
use App\Models\InternalNotificationSchedule;
use App\Models\Observability\OperationalAlert;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\Task;

/**
 * Canonical subject_kind → Eloquent model FQCN map for Activity projection.
 *
 * Deprecated subject kinds (Faz 1: producer removed) keep their historical FQCN string
 * so old activity rows stay readable; those model classes no longer exist.
 */
final class SubjectKindModelMap
{
    /**
     * @return class-string
     */
    public static function modelClass(DomainEventSubjectKind $kind): string
    {
        return match ($kind) {
            DomainEventSubjectKind::Finding => Finding::class,
            DomainEventSubjectKind::Opportunity => Opportunity::class,
            DomainEventSubjectKind::Recommendation => Recommendation::class,
            DomainEventSubjectKind::ClientRequest => 'App\\Models\\ClientRequest',
            DomainEventSubjectKind::Task => Task::class,
            DomainEventSubjectKind::QaReview => 'App\\Models\\QaReview',
            DomainEventSubjectKind::Approval => 'App\\Models\\Approval',
            DomainEventSubjectKind::Playbook => 'App\\Models\\Playbook',
            DomainEventSubjectKind::RecurringReviewRun => 'App\\Models\\RecurringReviewRun',
            DomainEventSubjectKind::BusinessOutcomeRecheckRun => BusinessOutcomeRecheckRun::class,
            DomainEventSubjectKind::InternalNotificationSchedule => InternalNotificationSchedule::class,
            DomainEventSubjectKind::OperationalAlert => OperationalAlert::class,
        };
    }
}
