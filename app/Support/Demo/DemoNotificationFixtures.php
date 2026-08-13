<?php

namespace App\Support\Demo;

/**
 * Deterministic in-app notifications for Demo Mode (no external channels).
 */
final class DemoNotificationFixtures
{
    /**
     * @return list<array{id: string, title: string, body: string, category: string, url: string, read: bool}>
     */
    public static function items(): array
    {
        return [
            [
                'id' => 'n-overdue-review',
                'title' => __('operator.notifications.demo.overdue_review_title'),
                'body' => __('operator.notifications.demo.overdue_review_body'),
                'category' => 'recurring_review',
                'url' => route('demo.work.show', ['workId' => 'rr-gads-overdue', 'type' => 'recurring_review']),
                'read' => false,
            ],
            [
                'id' => 'n-waiting-approval',
                'title' => __('operator.notifications.demo.approval_title'),
                'body' => __('operator.notifications.demo.approval_body'),
                'category' => 'approval',
                'url' => route('demo.tasks', ['view' => 'waiting_on_client']),
                'read' => false,
            ],
            [
                'id' => 'n-request',
                'title' => __('operator.notifications.demo.request_title'),
                'body' => __('operator.notifications.demo.request_body'),
                'category' => 'client_request',
                'url' => route('demo.work.show', ['workId' => 'req-doctor-title', 'type' => 'client_request']),
                'read' => false,
            ],
            [
                'id' => 'n-qa',
                'title' => __('operator.notifications.demo.qa_title'),
                'body' => __('operator.notifications.demo.qa_body'),
                'category' => 'qa',
                'url' => route('demo.work.show', ['workId' => 'appr-qa-creative', 'type' => 'approval']),
                'read' => true,
            ],
            [
                'id' => 'n-integration',
                'title' => __('operator.notifications.demo.integration_title'),
                'body' => __('operator.notifications.demo.integration_body'),
                'category' => 'system',
                'url' => route('demo.integrations'),
                'read' => true,
            ],
        ];
    }
}
