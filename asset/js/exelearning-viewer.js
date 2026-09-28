/**
 * eXeLearning Viewer JavaScript
 *
 * Provides functionality for the eXeLearning content viewer:
 * - Fullscreen toggle
 * - Iframe resizing
 */
(function() {
    'use strict';

    /**
     * Toggle fullscreen mode for an element.
     * @param {HTMLElement} element The element to toggle fullscreen
     */
    function toggleFullscreen(element) {
        if (!document.fullscreenElement &&
            !document.webkitFullscreenElement &&
            !document.mozFullScreenElement &&
            !document.msFullscreenElement) {
            // Enter fullscreen
            if (element.requestFullscreen) {
                element.requestFullscreen();
            } else if (element.webkitRequestFullscreen) {
                element.webkitRequestFullscreen();
            } else if (element.mozRequestFullScreen) {
                element.mozRequestFullScreen();
            } else if (element.msRequestFullscreen) {
                element.msRequestFullscreen();
            }
        } else {
            // Exit fullscreen
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            } else if (document.mozCancelFullScreen) {
                document.mozCancelFullScreen();
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
            }
        }
    }

    /**
     * Initialize viewer functionality.
     */
    function init() {
        // Handle fullscreen buttons
        document.querySelectorAll('.exelearning-fullscreen-btn').forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                var targetId = this.getAttribute('data-target');
                var iframe = document.getElementById(targetId);
                if (iframe) {
                    toggleFullscreen(iframe);
                }
            });
        });

        // Update button text on fullscreen change
        document.addEventListener('fullscreenchange', updateFullscreenButtons);
        document.addEventListener('webkitfullscreenchange', updateFullscreenButtons);
        document.addEventListener('mozfullscreenchange', updateFullscreenButtons);
        document.addEventListener('MSFullscreenChange', updateFullscreenButtons);
    }

    /**
     * Update fullscreen button states.
     */
    function updateFullscreenButtons() {
        var isFullscreen = !!(document.fullscreenElement ||
            document.webkitFullscreenElement ||
            document.mozFullScreenElement ||
            document.msFullscreenElement);

        // The stylesheet swaps the expand icon for a compress one.
        document.querySelectorAll('.exelearning-fullscreen-btn').forEach(function(button) {
            button.classList.toggle('is-fullscreen', isFullscreen);
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
