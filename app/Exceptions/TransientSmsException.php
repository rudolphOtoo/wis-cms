<?php

namespace App\Exceptions;

use Exception;

/**
 * Marker exception for retry-worthy SMS delivery failures.
 *
 * Thrown by MnotifySmsService when a failure looks transient
 * (HTTP 5xx, connection timeout, network blip). The queue worker
 * catches this, increments the job's retry counter, and re-runs
 * the job after the configured backoff delay.
 *
 * NOT thrown for permanent failures (invalid number, auth error,
 * 4xx response). Those return false from send() so the job records
 * a permanent failure without retrying.
 *
 * Convention: callers only catch this exception when they want
 * to OPT OUT of the retry behaviour. Default propagation is the
 * right behaviour - let it bubble up to the queue worker.
 */
class TransientSmsException extends Exception {}
