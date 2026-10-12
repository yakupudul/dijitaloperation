<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.14.0: the posts MoxDOP made, after they were sent. `GET /posts/status` says where they stand (draft,
 * scheduled, published, trashed); `POST /posts/{id}/date` dates one after an admin approved it in MoxDOP (a draft is
 * scheduled only with "Scheduled drafts" on, a published post gets a past date only with "Content updates" on). Posts
 * MoxDOP did not make are never touched.
 */
final class MoxDOP_Connector_Posts
{
    private $auth;

    public function __construct(MoxDOP_Connector_Auth $auth)
    {
        $this->auth = $auth;
    }

    public function register_routes($namespace)
    {
        // 1.14.0: where the posts MoxDOP made stand now (draft, scheduled, published, trashed) with date and link.
        register_rest_route($namespace, '/posts/status', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => [$this->auth, 'authorize'],
            'callback' => [$this, 'posts_status'],
            'args' => [
                'ids' => ['required' => true, 'type' => 'string', 'validate_callback' => static function ($value) {
                    return (bool) preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,99}$/', (string) $value);
                }],
            ],
        ]);
        // 1.14.0: the admin-approved date of a post MoxDOP made: a draft is dated or scheduled ("Scheduled drafts" on),
        // a published one gets a past date ("Content updates" on). The previous date and status come back for undo.
        register_rest_route($namespace, '/posts/(?P<id>[1-9][0-9]*)/date', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => [$this->auth, 'authorize'],
            'callback' => [$this, 'change_post_date'],
        ]);
    }

    /** @return array<string, mixed> status, dates (ISO 8601, UTC), link and featured image of a post MoxDOP made */
    public static function post_state($post_id)
    {
        $post = get_post($post_id);
        if (! $post || get_post_meta($post_id, '_moxdop_created', true) !== '1') {
            return ['post_id' => (int) $post_id, 'status' => 'missing'];
        }
        $gmt = static function ($value) {
            return is_string($value) && $value !== '' && strpos($value, '0000-00-00') !== 0 ? gmdate('c', strtotime($value.' UTC')) : null;
        };

        return [
            'post_id' => (int) $post_id,
            'status' => (string) $post->post_status,
            'title' => (string) get_the_title($post),
            'date_gmt' => $gmt($post->post_date_gmt),
            'modified_gmt' => $gmt($post->post_modified_gmt),
            'link' => $post->post_status === 'publish' ? (string) get_permalink($post) : '',
            'featured_media_id' => (int) get_post_thumbnail_id($post),
        ];
    }

    public function posts_status(WP_REST_Request $request)
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', explode(',', (string) $request->get_param('ids')))))), 0, 100);

        return $this->auth->envelope(['schema_version' => 1, 'posts' => array_map([self::class, 'post_state'], $ids)], $request);
    }

    public function change_post_date(WP_REST_Request $request)
    {
        $post_id = (int) $request->get_param('id');
        $post = get_post($post_id);
        if (! $post || get_post_meta($post_id, '_moxdop_created', true) !== '1') {
            return new WP_Error('moxdop_not_found', 'No MoxDOP post with this id.', ['status' => 404]);
        }
        $body = json_decode((string) $request->get_body(), true);
        $timestamp = is_array($body) && is_string($body['post_date'] ?? null) && $body['post_date'] !== '' ? strtotime($body['post_date']) : false;
        if ($timestamp === false) {
            return new WP_Error('moxdop_invalid_body', 'post_date is required.', ['status' => 400]);
        }
        $schedule = ! empty($body['schedule']);
        $before = self::post_state($post_id);
        if (in_array($post->post_status, ['draft', 'pending', 'future'], true)) {
            if ($schedule && $timestamp <= time()) {
                return new WP_Error('moxdop_invalid_date', 'A scheduled post needs a future date.', ['status' => 400]);
            }
            if ($schedule && ! MoxDOP_Connector_Drafts::scheduling_allowed()) {
                return new WP_Error('moxdop_schedule_disabled', 'Scheduled drafts are disabled on this site.', ['status' => 403]);
            }
            $status = $schedule ? 'future' : 'draft';
        } elseif ($post->post_status === 'publish') {
            if (! MoxDOP_Connector_Fixes::content_allowed()) {
                return new WP_Error('moxdop_content_disabled', 'Content updates are disabled on this site.', ['status' => 403]);
            }
            if ($timestamp > time() + 60) {
                return new WP_Error('moxdop_invalid_date', 'A published post cannot get a future date.', ['status' => 400]);
            }
            $status = 'publish';
        } else {
            return new WP_Error('moxdop_not_dateable', 'The post is neither a draft nor published.', ['status' => 409]);
        }
        $date_gmt = gmdate('Y-m-d H:i:s', $timestamp);
        $result = wp_update_post(['ID' => $post_id, 'post_status' => $status, 'post_date_gmt' => $date_gmt, 'post_date' => get_date_from_gmt($date_gmt), 'edit_date' => true], true);
        if (is_wp_error($result)) {
            return new WP_Error('moxdop_date_failed', $result->get_error_message(), ['status' => 500]);
        }
        clean_post_cache($post_id);

        return $this->auth->envelope(['schema_version' => 1, 'previous' => $before] + self::post_state($post_id), $request);
    }
}
