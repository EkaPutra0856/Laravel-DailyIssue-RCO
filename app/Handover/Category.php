<?php

namespace App\Handover;

enum Category: string
{
    case PENDING = 'pending';    // Putih: belum disentuh
    case PROGRESS = 'progress';  // Kuning: sedang dikerjakan
    case DONE = 'done';          // Hijau / status selesai
}
