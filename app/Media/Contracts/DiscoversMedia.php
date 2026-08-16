<?php

namespace App\Media\Contracts;

/**
 * Implemented only by providers with a real metadata API behind them.
 *
 * Scraping a site for a discovery feed is not the same capability and must not
 * be presented as one, so a provider without an API simply does not implement
 * this and never appears on the discover surface.
 *
 * Filled in during phase 3.
 */
interface DiscoversMedia extends Provider
{
    public function supportsCategories(): bool;
}
