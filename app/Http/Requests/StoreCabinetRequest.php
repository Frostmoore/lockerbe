<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCabinetRequest extends FormRequest
{
    /**
     * `code` e' unico **per tenant**, non globalmente: due locali diversi possono avere
     * entrambi un armadio "A1". La regola deve quindi restringersi al tenant corrente —
     * e ci arriva gratis, perche' il global scope filtra gia' la query di unicita'.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('cabinets', 'code')->where(
                    fn ($query) => $query->where('tenant_id', $this->user()?->tenant_id)
                ),
            ],
            'site_id' => ['nullable', 'uuid', 'exists:sites,id'],

            // Quanti vani generare. Il minimo e' 1: un armadio senza vani non e' un armadio.
            'lockers' => ['required', 'integer', 'min:1', 'max:512'],

            'settings' => ['nullable', 'array'],
            'settings.channels_per_board' => ['nullable', 'integer', 'min:1', 'max:64'],
        ];
    }
}
