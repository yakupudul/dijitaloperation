<?php

namespace App\Support\DomainEvents;

use App\Enums\DomainEventSubjectKind;
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
     * Returns null for deprecated subject kinds whose model was removed (Faz 1).
     *
     * @return class-string|null
     */
    public static function modelClass(DomainEventSubjectKind $kind): ?string
    {
        return match ($kind) {
            DomainEventSubjectKind::Finding => Finding::class,
            DomainEventSubjectKind::Opportunity => Opportunity::class,
            DomainEventSubjectKind::Recommendation => Recommendation::class,
            DomainEventSubjectKind::ClientRequest => null,
            DomainEventSubjectKind::Task => Task::class,
            DomainEventSubjectKind::QaReview => null,
            DomainEventSubjectKind::Approval => null,
            DomainEventSubjectKind::Playbook => null,
            DomainEventSubjectKind::RecurringReviewRun => null,
            DomainEventSubjectKind::BusinessOutcomeRecheckRun => null,
            DomainEventSubjectKind::InternalNotificationSchedule => InternalNotificationSchedule::class,
            DomainEventSubjectKind::OperationalAlert => OperationalAlert::class,
        };
    }
}
