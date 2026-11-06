<?php

namespace App\Http\Controllers\Daily;

use App\Http\Controllers\Controller;
use App\Http\Requests\Daily\PeriodeRequest;
use App\Services\Daily\ExportExcel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Unduh rekap periode sebagai file Excel. */
class ExportController extends Controller
{
    public function __invoke(PeriodeRequest $request, ExportExcel $excel): BinaryFileResponse
    {
        [$awal, $akhir] = $request->rentang();
        ['path' => $path, 'nama' => $nama] = $excel->buat($awal, $akhir, $request->timDipilih());

        return response()->download($path, $nama, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
