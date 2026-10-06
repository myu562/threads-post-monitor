<?php

namespace App\Http\Exceptions;

use RuntimeException;

/** Thrown inside an account-change transaction to roll it back; $message is a translation key shown to the admin. */
final class AccountGuardViolation extends RuntimeException {}
