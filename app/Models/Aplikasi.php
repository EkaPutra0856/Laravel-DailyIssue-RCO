<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Aplikasi dan team penanggung jawabnya (sumber rekomendasi Team saat polisi mengetik nama aplikasi). */
class Aplikasi extends Model
{
    protected $table = 'aplikasi';

    protected $fillable = ['nama', 'team', 'jumlah_issue'];

    /** Cari aplikasi tanpa peduli huruf besar/kecil & spasi berlebih. */
    public static function findByNama(string $nama): ?self
    {
        $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nama)));

        return $key === '' ? null : self::query()->whereRaw('LOWER(nama) = ?', [$key])->first();
    }

    /**
     * Catat pemakaian aplikasi. Aplikasi baru disimpan dengan team yang dipilih; aplikasi yang sudah ada
     * tidak diganti team-nya (supaya konsisten), hanya jumlah issue-nya bertambah.
     */
    public static function catat(string $nama, string $team): void
    {
        $nama = trim(preg_replace('/\s+/u', ' ', $nama));
        if ($nama === '') {
            return;
        }
        $app = self::findByNama($nama);
        if ($app) {
            $app->increment('jumlah_issue');
        } else {
            self::create(['nama' => $nama, 'team' => $team, 'jumlah_issue' => 1]);
        }
    }
}
