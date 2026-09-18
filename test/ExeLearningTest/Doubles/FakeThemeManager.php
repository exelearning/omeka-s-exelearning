<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Stand-in for `Omeka\Site\ThemeManager`.
 *
 * Present in both Omeka S 3 and S 4; only S 4 pairs it with a resource-page
 * block manager, which is the distinction the module probes.
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
