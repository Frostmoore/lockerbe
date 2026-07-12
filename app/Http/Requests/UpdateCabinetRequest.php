<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCabinetRequest extends FormRequest
{
    /**
     * ⚠️ `status` accetta SOLO `maintenance` e `online`, mai `offline`.
     *
     * `offline` non e' una decisione: e' un fatto, derivato dall'assenza di heartbeat
     * (MarkOfflineCabinets). Lasciare che qualcuno lo scriva a mano significherebbe poter
     * dichiarare "online" un armadio spento — e da F4 un armadio dichiarato online accetta
     * comandi di apertura che non arriveranno mai, o peggio arriveranno ore dopo.
     *
     * `maintenance` invece e' una scelta umana: "non toccatelo, ci sto lavorando".
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'site_id' => ['nullable', 'uuid', 'exists:sites,id'],
            'status' => ['sometimes', 'in:maintenance,online'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
