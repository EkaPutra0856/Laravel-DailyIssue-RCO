<?php

namespace App\Handover;

/** Susun pesan rekap Telegram (HTML). */
final class Report
{
    // Batas Telegram 4096 karakter setelah parsing HTML; sisakan ruang untuk tag & penanda part.
    public const MAX_LEN = 3800;
    private const SEPARATOR = '------------------------------------';

    private const HEADINGS = [
        'pending' => '📌 <b>Pendingan Issue (Belum Dikerjakan):</b>',
        'progress' => '⏳ <b>Issue On Progress (Kuning):</b>',
        'done' => '✅ <b>Issue Done (Hijau):</b>',
    ];
    private const EMPTY = [
        'pending' => '<i>Tidak ada issue pending</i>',
        'progress' => '<i>Tidak ada issue on progress</i>',
        'done' => '<i>Tidak ada issue done</i>',
    ];
    private const TOTALS = [
        'pending' => 'Total Pending: %d Issue (Putih)',
        'progress' => 'Total On Progress: %d Issue (Kuning)',
        'done' => 'Total Done: %d Issue (Hijau)',
    ];
    private const CLOSING = ['Mohon bantuannya untuk ditelusuri rekan-rekan engineer', 'Terima kasih.'];

    private static function app(Issue $issue, bool $detail): string
    {
        return $detail && $issue->aplikasi !== '' ? ' — '.Py::escape($issue->aplikasi) : '';
    }

    /** Urutkan stabil berdasarkan kunci array (setara sorted(key=tuple)). */
    private static function sortBy(array $items, callable $key): array
    {
        usort($items, fn (Issue $a, Issue $b) => $key($a) <=> $key($b));

        return $items;
    }

    /** @param  Issue[]  $issues */
    private static function section(Category $cat, array $issues, bool $detail, ?array $teams): array
    {
        $lines = [self::HEADINGS[$cat->value], ''];
        $items = array_values(array_filter($issues, fn (Issue $i) => $i->category === $cat));
        if (! $items) {
            return [...$lines, self::EMPTY[$cat->value], ''];
        }

        if ($cat === Category::PENDING) {
            foreach ($teams ?: Teams::ALL as $team) {
                $teamItems = self::sortBy(
                    array_filter($items, fn (Issue $i) => $i->team === $team),
                    fn (Issue $i) => [$i->tanggal->getTimestamp(), $i->issueNo],
                );
                // Tim 1-3 selalu tampil supaya jelas tim yang kosong memang tidak punya pendingan.
                if (! $teamItems && $team === 'ALL TEAM' && ! $teams) {
                    continue;
                }
                $lines[] = "<b>{$team}</b>";
                foreach ($teamItems as $i) {
                    $lines[] = Dates::fmt($i->tanggal)." — Issue {$i->issueNo}".self::app($i, $detail);
                }
                if (! $teamItems) {
                    $lines[] = '<i>Tidak ada pendingan</i>';
                }
                $lines[] = '';
            }
        } else {
            $order = array_flip(Teams::ALL);
            $sorted = self::sortBy($items, fn (Issue $i) => [$order[$i->team], $i->tanggal->getTimestamp(), $i->issueNo]);
            foreach ($sorted as $i) {
                $wait = match (true) {
                    $i->trouble => ' 🔴 <i>(trouble)</i>',
                    $i->waitingExternal => ' 🟠 <i>(menunggu eksternal)</i>',
                    default => '',
                };
                $lines[] = Dates::fmt($i->tanggal)." — {$i->team} — Issue {$i->issueNo}".self::app($i, $detail)
                    .' — '.Py::escape($i->label()).$wait;
            }
            $lines[] = '';
        }

        return $lines;
    }

    private static function header(Query $query, string $title, array $warnings): array
    {
        if ($query->detail) {
            $title = str_contains($title, ' – ')
                ? preg_replace('/ – /u', ' (Detail) – ', $title, 1)
                : "{$title} (Detail)";
        }
        $lines = ['<b>'.Py::escape($title).'</b>', '', Dates::fmtRange($query->start, $query->end), ''];
        foreach ($warnings as $w) {
            $lines[] = '⚠️ <i>'.Py::escape($w).'</i>';
        }
        if ($warnings) {
            $lines[] = '';
        }

        return $lines;
    }

    /** @param  Issue[]  $issues  @param  Category[]  $cats */
    private static function footer(Query $query, array $issues, array $cats, bool $grandTotal): array
    {
        $count = fn (array $in) => count(array_filter($issues, fn (Issue $i) => in_array($i->category, $in, true)));
        $lines = [self::SEPARATOR];
        foreach ($cats as $cat) {
            $lines[] = sprintf(self::TOTALS[$cat->value], $count([$cat]));
        }
        if ($grandTotal && count($query->categories) > 1) {
            $lines[] = 'Total Keseluruhan: '.$count($query->categories).' Issue';
        }
        if ($grandTotal && array_intersect(array_map(fn ($c) => $c->value, $query->categories), ['pending', 'progress'])) {
            $lines = [...$lines, '', ...self::CLOSING];
        }

        return $lines;
    }

    /**
     * Daftar pesan siap kirim (sudah dipecah sesuai batas Telegram).
     *
     * @param  Issue[]  $issues
     * @return string[]
     */
    public static function buildMessages(array $issues, Query $query, string $title, array $warnings = []): array
    {
        if (! $query->split || count($query->categories) === 1) {
            $lines = self::header($query, $title, $warnings);
            foreach ($query->categories as $cat) {
                $lines = [...$lines, ...self::section($cat, $issues, $query->detail, $query->teams)];
            }

            return self::chunk([...$lines, ...self::footer($query, $issues, $query->categories, true)]);
        }

        $messages = [];
        $last = count($query->categories) - 1;
        foreach ($query->categories as $n => $cat) {
            $lines = self::header($query, $title, $n === 0 ? $warnings : []);
            $lines = [...$lines, ...self::section($cat, $issues, $query->detail, $query->teams)];
            $lines = [...$lines, ...self::footer($query, $issues, [$cat], $n === $last)];
            $messages = [...$messages, ...self::chunk($lines)];
        }

        return $messages;
    }

    /**
     * Pecah per baris utuh supaya tag HTML tidak terpotong.
     *
     * @param  string[]  $lines
     * @return string[]
     */
    public static function chunk(array $lines, int $limit = self::MAX_LEN): array
    {
        $parts = [];
        $cur = '';
        foreach ($lines as $line) {
            $candidate = $cur !== '' ? "{$cur}\n{$line}" : $line;
            if (Py::len($candidate) > $limit && Py::strip($cur) !== '') {
                $parts[] = Py::strip($cur);
                $cur = $line;
            } else {
                $cur = $candidate;
            }
        }
        if (Py::strip($cur) !== '') {
            $parts[] = Py::strip($cur);
        }
        if (count($parts) > 1) {
            $total = count($parts);
            foreach ($parts as $i => $p) {
                $parts[$i] = "{$p}\n\n<i>(Part ".($i + 1)."/{$total})</i>";
            }
        }

        return $parts;
    }

    public static function helpText(): string
    {
        return implode("\n", [
            '📖 <b>Panduan Bot Handover</b>',
            '',
            '<b>Perintah</b>',
            '/rekap — pending + on progress',
            '/pending — hanya yang belum tersentuh (putih)',
            '/progress — hanya yang masih on going (kuning)',
            '/done — hanya yang sudah selesai (hijau)',
            '/doneprogress — done &amp; on progress, 2 bubble chat',
            '/rekapdetail — rekap + nama aplikasi',
            '/id — tampilkan chat id',
            '',
            '<b>Periode</b> (bisa dipakai di semua perintah)',
            '<code>/rekap</code> — hari ini',
            '<code>/rekap kemarin</code> | <code>minggu ini</code> | <code>bulan ini</code>',
            '<code>/rekap Oktober</code> atau <code>/rekap Oktober 2026</code>',
            '<code>/rekap 13 Oktober 2026</code> atau <code>/rekap 13/10/2026</code>',
            '<code>/rekap 5 Oktober 2026 : 7 Oktober 2026</code>',
            '<code>/rekap 5-7 Oktober 2026</code>',
            '<code>/rekap 28 September 2026 : 2 Oktober 2026</code> (lintas bulan)',
            '<code>/rekap September : November 2026</code>',
            '',
            '<b>Tambahan</b>',
            '<code>detail</code> — tampilkan nama aplikasi, mis. <code>/pending Oktober detail</code>',
            '<code>pisah</code> — tiap kategori di bubble terpisah, mis. <code>/rekap Oktober pisah</code>',
            '<code>pending progress done</code> — pilih kategori, mis. <code>/rekap done pending Oktober</code>',
        ]);
    }
}
