<?php

namespace App\Http\Controllers\Daily;

use App\Http\Controllers\Controller;
use App\Models\Aplikasi;
use Illuminate\Http\JsonResponse;

/** Daftar aplikasi dan timnya, untuk rekomendasi saat polisi mengetik nama aplikasi. */
class AplikasiController extends Controller
{
    public function index(): JsonResponse
    {
        return self::json(Aplikasi::query()->orderByDesc('jumlah_issue')->orderBy('nama')
            ->get(['nama', 'team', 'jumlah_issue'])->toArray());
    }
}
