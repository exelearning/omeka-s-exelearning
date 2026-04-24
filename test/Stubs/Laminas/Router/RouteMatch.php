<?php
declare(strict_types=1);

namespace Laminas\Router;

/**
 * Minimal RouteMatch stub for tests that exercise controller actions
 * relying on routed parameters (`$this->params()->fromRoute()` /
 * `$this->params('slug')`). Mirrors the fields the Laminas MVC param
 * plugin reads off a RouteMatch instance.
 */
class RouteMatch
{
    /** @var array<string,mixed> */
    private array $params;
    private ?string $matchedRouteName = null;

    public function __construct(array $params = [])
    {
        $this->params = $params;
    }

    public function getParam(string $name, $default = null)
    {
        return $this->params[$name] ?? $default;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function setParam(string $name, $value): self
    {
        $this->params[$name] = $value;
        return $this;
    }

    public function setMatchedRouteName(string $name): self
    {
        $this->matchedRouteName = $name;
        return $this;
    }

    public function getMatchedRouteName(): ?string
    {
        return $this->matchedRouteName;
    }
}
