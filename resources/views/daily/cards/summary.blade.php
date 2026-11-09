<div class="section-label">Ringkasan</div>
<div class="tiles" id="tiles">
  @foreach (['pending' => ['slate', 'Belum dikerjakan'], 'onprogress' => ['yellow', 'Sedang dikerjakan'], 'trouble' => ['red', 'Ada kendala'], 'done' => ['green', 'Selesai']] as $key => [$color, $desc])
    <button class="tile" data-status="{{ $key }}" title="Klik untuk tampilkan/sembunyikan {{ $statuses[$key] }}">
      <span class="tile-icon {{ $color }}">
        @switch($key)
          @case('pending')<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>@break
          @case('onprogress')<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>@break
          @case('trouble')<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg>@break
          @default<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        @endswitch
      </span>
      <span class="tile-body">
        <span class="tile-num" data-count="{{ $key }}">–</span>
        <span class="tile-title">{{ $statuses[$key] }}</span>
        <span class="tile-desc">{{ $desc }}</span>
      </span>
    </button>
  @endforeach
</div>
