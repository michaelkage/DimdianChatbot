<?php
declare(strict_types=1);

/**
 * Versioned compatibility entry point.
 *
 * v1 and legacy aliases share the same HTTP handler. Application use cases
 * and persistence are delegated to their service/repository boundaries.
 */
require dirname(__DIR__) . '/session.php';
