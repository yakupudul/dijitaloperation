<?php

namespace App\Services\Integrations\WordPress;

use RuntimeException;

/**
 * A site that fetches its work itself (connector 1.13.0+, `rest_transport` pull) did not take the request in time; it was
 * withdrawn, so nothing ran on the site and the write can safely be sent again later (Avrupadent, 2026-10-14: 18
 * approved articles and ~1000 site fixes ended as failed while the site was not asking, and nothing reached WordPress).
 */
final class SiteDidNotTakeWork extends RuntimeException {}
