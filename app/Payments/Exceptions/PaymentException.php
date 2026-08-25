<?php

namespace App\Payments\Exceptions;

use Exception;

/**
 * Base for faults in the payments module itself — misconfiguration, an unusable
 * recipient — as distinct from a provider declining a transfer, which is reported as
 * a failed PaymentResult rather than thrown.
 */
class PaymentException extends Exception
{
    //
}
