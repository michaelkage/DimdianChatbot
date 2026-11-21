<?php
declare(strict_types=1);

/**
 * Versioned compatibility entry point.
 *
 * The v1 contract currently delegates to the proven PHP implementation.
 * Keep transport/security behaviour in the existing handler until the
 * application-service and storage boundaries are extracted.
 */
require dirname(__DIR__) . '/messages.php';
