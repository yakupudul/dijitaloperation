<?php

namespace App\Support\DomainEvents;

use App\Enums\DomainEventSubjectKind;
use App\Models\Approval;
use App\Models\ClientRequest;
use App\Models\Finding;
use App\Models\InternalNotificationSchedule;
use App\Models\Observability\OperationalAlert;
use App\Models\Opportunity;
use App\Models\Playbook;
use App\Models\QaReview;
use App\Models\Recommendation;
use App\Models\RecurringReviewRun;
use App\Models\Task;

/**
 * Canonical subject_kind → Eloquent model FQCN map for Activity projection.
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
            DomainEventSubjectKind::ClientRequest => ClientRequest::class,
            DomainEventSubjectKind::Task => Task::class,
            DomainEventSubjectKind::QaReview => QaReview::class,
            DomainEventSubjectKind::Approval => Approval::class,
            DomainEventSubjectKind::Playbook => Playbook::class,
            DomainEventSubjectKind::RecurringReviewRun => RecurringReviewRun::class,
            DomainEventSubjectKind::BusinessOutcomeRecheckRun => null,
            DomainEventSubjectKind::InternalNotificationSchedule => InternalNotificationSchedule::class,
            DomainEventSubjectKind::OperationalAlert => OperationalAlert::class,
        };
    }
}
