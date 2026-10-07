<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Traits\Helpers\AvailableLanguages;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('System', 'Client endpoints for system metadata and available locales.')]
class LanguagesController extends ClientApiController
{
    use AvailableLanguages;

    private const array LANGUAGES_EXAMPLE = [
        'en' => 'English',
    ];

    /**
     * Return languages available for Panel locale selectors.
     */
    #[Endpoint('List client languages', 'Returns the languages available for panel locale selectors.')]
    #[ScribeResponse(self::LANGUAGES_EXAMPLE, description: 'Available languages returned.')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->getAvailableLanguages(true));
    }
}

