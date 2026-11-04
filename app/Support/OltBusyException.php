<?php

namespace App\Support;

/**
 * Thrown inside a command when the OLT is busy (another delete, or an active
 * PSB / migration run); nothing was written for the operation that threw.
 */
final class OltBusyException extends \RuntimeException {}
