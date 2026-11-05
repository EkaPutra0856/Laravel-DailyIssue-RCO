<?php

namespace App\Handover\Sources;

use RuntimeException;

/** Sumber data tidak bisa dibaca; pesannya aman ditampilkan (tanpa key). */
final class SourceError extends RuntimeException {}
