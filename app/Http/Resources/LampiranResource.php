<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transformasi file lampiran ke format respons JSON.
 *
 * @mixin \App\Models\Lampiran
 */
class LampiranResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'mime' => $this->mime,
            'ukuran' => $this->ukuran,
            'gambar' => $this->isGambar(),
            'url' => url("/api/daily/lampiran/{$this->id}"),
        ];
    }
}
