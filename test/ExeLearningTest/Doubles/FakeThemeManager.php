<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Stand-in for `Omeka\Site\ThemeManager`.
 *
 * The module asks it for the current theme so the resource-page block manager
 * can resolve that theme's configuration.
 */
class FakeThemeManager
{
    /** @var object|null */
    private $theme;

    /**
     * @param object|null $theme
     */
    public function __construct($theme = null)
    {
        $this->theme = $theme ?? new class {
            public function getSettingsKey(): string
            {
                return 'theme_settings_default';
            }

            public function getConfigSpec(): array
            {
                return [];
            }
        };
    }

    /**
     * @return object|null
     */
    public function getCurrentTheme()
    {
        return $this->theme;
    }
}
