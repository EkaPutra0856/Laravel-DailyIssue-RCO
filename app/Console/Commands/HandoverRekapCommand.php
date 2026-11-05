<?php

namespace App\Console\Commands;

use App\Handover\CommandError;
use App\Handover\Commands;
use App\Handover\Dates;
use App\Handover\IssueService;
use App\Handover\Report;
use App\Handover\Sources\Source;
use App\Handover\Sources\SourceError;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Coba rekap tanpa Telegram: php artisan handover:rekap "/rekap Oktober 2026" --stats */
class HandoverRekapCommand extends Command
{
    protected $signature = 'handover:rekap
        {perintah : perintah seperti di Telegram, mis. "/rekap Oktober 2026"}
        {--today= : anggap hari ini tanggal YYYY-MM-DD}
        {--stats : tampilkan statistik klasifikasi}';

    protected $description = 'Tes rekap dari terminal (tidak mengirim apa pun ke Telegram)';

    public function handle(): int
    {
        if ($this->option('today')) {
            $today = Dates::fromIso($this->option('today'));
            if (! $today) {
                $this->error('--today harus berformat YYYY-MM-DD');

                return 1;
            }
        } else {
            $now = CarbonImmutable::now(config('handover.timezone'));
            $today = Dates::make($now->year, $now->month, $now->day);
        }

        $text = trim($this->argument('perintah'));
        if (! str_starts_with($text, '/')) {
            $text = '/'.$text;
        }
        try {
            $query = Commands::parse($text, $today);
            [$issues, $warnings] = (new IssueService(app(Source::class)))->collect($query);
        } catch (CommandError|SourceError $e) {
            $this->error("❌ {$e->getMessage()}");

            return 1;
        }

        $messages = Report::buildMessages($issues, $query, (string) config('handover.report_title'), $warnings);
        foreach ($messages as $n => $msg) {
            $this->line(sprintf('===== Pesan %d/%d (%d karakter) =====', $n + 1, count($messages), mb_strlen($msg)));
            $this->line($msg);
        }
        if ($this->option('stats')) {
            $count = function (callable $key) use ($issues) {
                $out = [];
                foreach ($issues as $i) {
                    if (($k = $key($i)) !== '') {
                        $out[$k] = ($out[$k] ?? 0) + 1;
                    }
                }

                return json_encode($out, JSON_UNESCAPED_UNICODE);
            };
            $this->line('===== Statistik =====');
            $this->line('Kategori: '.$count(fn ($i) => $i->category->value));
            $this->line('Alasan done: '.$count(fn ($i) => $i->doneReason));
            $this->line('Tim: '.$count(fn ($i) => $i->team));
        }

        return 0;
    }
}
