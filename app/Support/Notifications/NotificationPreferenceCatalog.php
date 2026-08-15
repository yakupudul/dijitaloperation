<?php

namespace App\Support\Notifications;

/**
 * Frozen Settings → Notifications preference keys (in-app). Email is persisted only.
 */
final class NotificationPreferenceCatalog
{
    public const string CRITICAL_FINDING = 'critical_finding';

    public const string TASK_ASSIGNED = 'task_assigned';

    public const string CLIENT_REQUEST_RECEIVED = 'client_request_received';

    public const string APPROVAL_WAITING = 'approval_waiting';

    public const string QA_REVIEW_REQUIRED = 'qa_review_required';

    public const string RECURRING_REVIEW_DUE = 'recurring_review_due';

    public const string INTEGRATION_FAILURE = 'integration_failure';

    public const string TASK_OVERDUE = 'task_overdue';

    public const string WORK_ITEM_OVERDUE = 'work_item_overdue';

    public const string REGRESSION_OBSERVED = 'regression_observed';

    public const string PROVIDER_AUTHORIZATION_ISSUE = 'provider_authorization_issue';

    public const string OPERATION_FAILED = 'operation_failed';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            self::CRITICAL_FINDING,
            self::INTEGRATION_FAILURE,
            self::TASK_ASSIGNED,
            self::TASK_OVERDUE,
            self::WORK_ITEM_OVERDUE,
            self::CLIENT_REQUEST_RECEIVED,
            self::APPROVAL_WAITING,
            self::QA_REVIEW_REQUIRED,
            self::RECURRING_REVIEW_DUE,
            self::REGRESSION_OBSERVED,
            self::PROVIDER_AUTHORIZATION_ISSUE,
            self::OPERATION_FAILED,
        ];
    }

    /**
     * @return array<string, string> preference_key => English label
     */
    public static function labels(): array
    {
        return [
            self::CRITICAL_FINDING => 'Critical Finding',
            self::INTEGRATION_FAILURE => 'Integration failure',
            self::TASK_ASSIGNED => 'Task assigned',
            self::TASK_OVERDUE => 'Task overdue',
            self::WORK_ITEM_OVERDUE => 'Work item overdue',
            self::CLIENT_REQUEST_RECEIVED => 'Client request received',
            self::APPROVAL_WAITING => 'Approval waiting',
            self::QA_REVIEW_REQUIRED => 'QA review required',
            self::RECURRING_REVIEW_DUE => 'Recurring review due',
            self::REGRESSION_OBSERVED => 'Regression observed',
            self::PROVIDER_AUTHORIZATION_ISSUE => 'Provider authorization issue',
            self::OPERATION_FAILED => 'Operation failed',
        ];
    }

    /**
     * Default preference rows for Settings UI (in-app on, email off).
     *
     * @return list<array{preference_key: string, label: string, in_app_enabled: bool, email_enabled: bool}>
     */
    public static function defaults(): array
    {
        $rows = [];
        foreach (self::labels() as $key => $label) {
            $rows[] = [
                'preference_key' => $key,
                'label' => $label,
                'in_app_enabled' => true,
                'email_enabled' => false,
            ];
        }

        return $rows;
    }

    public static function isKnown(string $preferenceKey): bool
    {
        return in_array($preferenceKey, self::keys(), true);
    }
}
