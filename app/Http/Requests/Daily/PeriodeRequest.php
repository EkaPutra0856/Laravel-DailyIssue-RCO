<?php

namespace App\Http\Requests\Daily;

use App\Handover\Teams;
use App\Handover\Web\HandoverWeb;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** Filter ?start=&end=&teams= di URL. Tanpa tanggal berarti hari ini. */
class PeriodeRequest extends FormRequest
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function rentang(): array
    {
        $hariIni = CarbonImmutable::now(config('handover.timezone'))->toDateString();

        return HandoverWeb::parseRange($this->query('start', $hariIni), $this->query('end', $hariIni));
    }

    /** ?teams=Tim 1,Tim 2 (?team= tetap diterima). Hasil kosong berarti semua tim. */
    public function timDipilih(): array
    {
        $diminta = explode(',', (string) $this->query('teams', $this->query('team', '')));
        $teams = array_values(array_intersect(Teams::ALL, $diminta));

        return count($teams) < count(Teams::ALL) ? $teams : [];
    }
}
