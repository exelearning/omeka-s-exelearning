<?php
declare(strict_types=1);

namespace Laminas\Mvc;

use Laminas\Router\RouteMatch;

/**
 * Minimal MvcEvent stub for controller tests. Carries the RouteMatch
 * used by the `params()` plugin to resolve `:slug` / `:file` in tests.
 */
class MvcEvent
{
    private ?RouteMatch $routeMatch = null;

    public function setRouteMatch(RouteMatch $match): self
    {
        $this->routeMatch = $match;
        return $this;
    }

    public function getRouteMatch(): ?RouteMatch
    {
        return $this->routeMatch;
    }
}
