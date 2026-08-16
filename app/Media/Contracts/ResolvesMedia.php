<?php

namespace App\Media\Contracts;

use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\Data\ResolvedMedia;

interface ResolvesMedia extends Provider
{
    /**
     * @throws ApiException
     */
    public function resolve(MediaRequest $request): ResolvedMedia;
}
