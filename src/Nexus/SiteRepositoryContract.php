<?php

namespace Sztyup\Nexus;

use Illuminate\Support\Collection;

interface SiteRepositoryContract
{
    /**
     * Returns all site models, implementing SiteModelContract
     *
     * @return Collection|SiteModelContract[]
     */
    public function getAll(): Collection;
}