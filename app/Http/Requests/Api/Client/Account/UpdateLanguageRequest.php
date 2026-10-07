<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Account;

use Illuminate\Validation\Rule;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Traits\Helpers\AvailableLanguages;

class UpdateLanguageRequest extends ClientApiRequest
{
    use AvailableLanguages;

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'language' => ['required', 'string', Rule::in(array_keys($this->getAvailableLanguages()))],
        ];
    }
}

