<?php

namespace App\Domain\Billing\Razorpay;

use RuntimeException;

/** The gateway refused a call or could not be reached. The message is safe to log, not to show. */
class RazorpayException extends RuntimeException {}
