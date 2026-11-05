<?php

namespace App\Handover;

use InvalidArgumentException;

/** Perintah tidak bisa dipahami; pesannya ditampilkan ke user. */
final class CommandError extends InvalidArgumentException {}
