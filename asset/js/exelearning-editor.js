/**
 * eXeLearning Editor Modal Handler for Omeka S
 *
 * Opens the editor page in a fullscreen modal for editing .elpx files.
 */
(function() {
    'use strict';

    var ExeLearningEditor = {
        modal: null,
        iframe: null,
        saveBtn: null,
        closeBtn: null,
        loadingModal: null,
        currentMediaId: null,
        isOpen: false,
        isSaving: false,
        hasUnsavedChanges: false,

        /**
         * Initialize the editor.
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Build the modal on first use. The edit button can appear on any
         * page that renders the media (admin or public), so the markup lives
         * here rather than in a page-specific template.
         */
        ensureModal: function() {
            if (this.modal) {
                return;
            }
            var self = this;
            var i18n = window.exelearningEditorI18n || {};

            var modal = document.createElement('div');
            modal.id = 'exelearning-editor-modal';
            modal.className = 'exelearning-editor-modal';

            var header = document.createElement('div');
            header.className = 'exelearning-editor-header';

            var title = document.createElement('div');
            title.className = 'exelearning-editor-title';
            var icon = document.createElement('span');
            icon.className = 'icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = '\u270E';
            var titleText = document.createElement('span');
            titleText.textContent = i18n.title || 'Edit eXeLearning File';
            title.appendChild(icon);
            title.appendChild(titleText);

            var actions = document.createElement('div');
            actions.className = 'exelearning-editor-actions';
            var saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.id = 'exelearning-editor-save';
            saveBtn.className = 'button button-primary';
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.id = 'exelearning-editor-close';
            closeBtn.className = 'button button-secondary';
            closeBtn.textContent = i18n.close || 'Close';
            actions.appendChild(saveBtn);
            actions.appendChild(closeBtn);

            header.appendChild(title);
            header.appendChild(actions);
            modal.appendChild(header);
            document.body.appendChild(modal);

            this.modal = modal;
            this.saveBtn = saveBtn;
            this.closeBtn = closeBtn;

            saveBtn.addEventListener('click', function() { self.requestSave(); });
            closeBtn.addEventListener('click', function() { self.close(); });

            // Start save button as disabled (enabled on DOCUMENT_LOADED)
            saveBtn.disabled = true;
            this.updateSaveButtonContent(false);
        },

        /**
         * Bind page-wide event handlers.
         */
        bindEvents: function() {
            var self = this;

            window.addEventListener('message', function(event) { self.handleMessage(event); });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && self.isOpen) {
                    self.close();
                }
            });
        },

        /**
         * Update save button content with icon.
         *
         * @param {boolean} saving Whether save is in progress.
         */
        updateSaveButtonContent: function(saving) {
            if (!this.saveBtn) {
                return;
            }
            var i18n = window.exelearningEditorI18n || {};
            var label = saving
                ? (i18n.saving || 'Saving...')
                : (i18n.saveButton || 'Save to Omeka');
            this.saveBtn.innerHTML =
                '<span class="o-icon-upload" aria-hidden="true"></span> ' + label;
        },

        /**
         * Request save from the iframe.
         */
        requestSave: function() {
            if (this.isSaving || !this.iframe) {
                return;
            }

            var iframeWindow = this.iframe.contentWindow;
            if (iframeWindow) {
                // The editor is served same-origin; never broadcast to
                // whatever origin the iframe may have navigated to.
                iframeWindow.postMessage({ type: 'exelearning-request-save' }, window.location.origin);
            }
        },

        /**
         * Create the loading modal element.
         */
        createLoadingModal: function() {
            if (this.loadingModal) {
                return;
            }
            var i18n = window.exelearningEditorI18n || {};
            var savingText = i18n.saving || 'Saving...';
            var waitText = i18n.savingWait || 'Please wait while the file is being saved.';
            var closeText = i18n.close || 'Close';

            var div = document.createElement('div');
            div.className = 'exelearning-loading-modal';
            div.id = 'exelearning-loading-modal';
            div.innerHTML =
                '<div class="exelearning-loading-modal__content">' +
                    '<div class="exelearning-loading-modal__spinner"></div>' +
                    '<h3 class="exelearning-loading-modal__title">' + savingText + '</h3>' +
                    '<p class="exelearning-loading-modal__message">' + waitText + '</p>' +
                    '<div class="exelearning-loading-modal__error">' +
                        '<p class="exelearning-loading-modal__error-text"></p>' +
                        '<button type="button" class="button exelearning-loading-modal__close">' + closeText + '</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(div);
            this.loadingModal = div;

            var self = this;
            div.querySelector('.exelearning-loading-modal__close').addEventListener('click', function() {
                self.hideLoadingModal();
            });
        },

        /**
         * Show the loading modal.
         */
        showLoadingModal: function() {
            this.createLoadingModal();
            this.loadingModal.classList.remove('is-error');
            this.loadingModal.classList.add('is-visible');
        },

        /**
         * Hide the loading modal.
         */
        hideLoadingModal: function() {
            if (this.loadingModal) {
                this.loadingModal.classList.remove('is-visible', 'is-error');
            }
        },

        /**
         * Remove the loading modal from DOM.
         */
        removeLoadingModal: function() {
            if (this.loadingModal) {
                this.loadingModal.remove();
                this.loadingModal = null;
            }
        },

        /**
         * Show error in the loading modal.
         *
         * @param {string} message The error message.
         */
        showLoadingError: function(message) {
            this.createLoadingModal();
            this.loadingModal.classList.add('is-error');
            var errorText = this.loadingModal.querySelector('.exelearning-loading-modal__error-text');
            if (errorText) {
                errorText.textContent = message;
            }
        },

        /**
         * Set saving state and update button.
         *
         * @param {boolean} saving Whether save is in progress.
         */
        setSavingState: function(saving) {
            this.isSaving = saving;
            if (this.saveBtn) {
                this.saveBtn.disabled = saving;
                this.updateSaveButtonContent(saving);
            }
            if (saving) {
                this.showLoadingModal();
            } else {
                this.hideLoadingModal();
            }
        },

        /**
         * Open the editor modal.
         *
         * @param {number} mediaId The media ID.
         * @param {string} editorUrl The editor URL.
         */
        open: function(mediaId, editorUrl) {
            if (!mediaId || !editorUrl) {
                console.error('ExeLearningEditor: Missing mediaId or editorUrl');
                return;
            }

            this.currentMediaId = mediaId;
            this.hasUnsavedChanges = false;
            this.ensureModal();

            // Recreate iframe if it was destroyed by a previous close/save
            if (!this.iframe) {
                var iframe = document.createElement('iframe');
                iframe.id = 'exelearning-editor-iframe';
                iframe.className = 'exelearning-editor-iframe';
                this.modal.appendChild(iframe);
                this.iframe = iframe;
            }

            this.modal.style.display = 'flex';
            this.isOpen = true;
            this.iframe.src = editorUrl;
            document.body.classList.add('exelearning-editor-open');

            // Start save button as disabled until document loads
            if (this.saveBtn) {
                this.saveBtn.disabled = true;
                this.updateSaveButtonContent(false);
            }
        },

        /**
         * Close the editor modal.
         */
        close: function() {
            if (!this.isOpen) {
                return;
            }

            // Check for unsaved changes
            if (this.hasUnsavedChanges) {
                var i18n = window.exelearningEditorI18n || {};
                var message = i18n.unsavedChanges ||
                    'You have unsaved changes. Are you sure you want to close?';
                if (!window.confirm(message)) {
                    return;
                }
            }

            // Destroy the iframe to prevent beforeunload dialog
            this.destroyIframe();

            // Hide modal
            if (this.modal) {
                this.modal.style.display = 'none';
            }
            this.isOpen = false;
            this.hasUnsavedChanges = false;

            // Remove body class
            document.body.classList.remove('exelearning-editor-open');

            // Reset state
            this.currentMediaId = null;
        },

        /**
         * Handle messages from iframe.
         *
         * @param {MessageEvent} event The message event.
         */
        handleMessage: function(event) {
            // Only trust messages coming from our own editor iframe at our own
            // origin. The editor is embedded same-origin; rejecting everything
            // else stops the sandboxed preview (or any other frame) from
            // spoofing save-complete/close and silently discarding edits.
            if (event.origin !== window.location.origin) {
                return;
            }
            if (!this.iframe || event.source !== this.iframe.contentWindow) {
                return;
            }

            var data = event.data;

            if (!data || !data.type) {
                return;
            }

            switch (data.type) {
                case 'exelearning-bridge-ready':
                    // Bridge is ready
                    console.log('ExeLearningEditor: Bridge ready');
                    break;

                case 'exelearning-save-start':
                    this.setSavingState(true);
                    break;

                case 'exelearning-save-complete':
                    this.setSavingState(false);
                    this.hasUnsavedChanges = false;
                    this.onSaveComplete(data);
                    break;

                case 'exelearning-save-error':
                    this.setSavingState(false);
                    this.showLoadingError(data.message || 'Save failed');
                    console.error('ExeLearningEditor: Save failed -', data.message || 'Unknown error');
                    break;

                case 'exelearning-close':
                    this.close();
                    break;

                case 'DOCUMENT_LOADED':
                    if (!this.isSaving && this.saveBtn) {
                        this.saveBtn.disabled = false;
                    }
                    this.hasUnsavedChanges = false;
                    break;

                case 'DOCUMENT_CHANGED':
                    this.hasUnsavedChanges = true;
                    break;
            }
        },

        /**
         * Destroy the iframe to prevent beforeunload dialogs.
         */
        destroyIframe: function() {
            if (!this.iframe) {
                return;
            }
            try {
                // Remove beforeunload handlers from the iframe's window
                this.iframe.contentWindow.onbeforeunload = null;
            } catch (e) {
                // Cross-origin or already detached
            }
            // Remove the iframe from DOM entirely - this prevents
            // any addEventListener('beforeunload') handlers from firing
            this.iframe.remove();
            this.iframe = null;
        },

        /**
         * Handle save complete.
         *
         * @param {object} data The message data.
         */
        onSaveComplete: function(data) {
            // Saving creates a NEW extraction hash and deletes the old one, so
            // every element still pointing at the old hash (the preview iframe
            // AND the "open in new tab"/"fullscreen" link) must be repointed —
            // otherwise the stale links 404 against the now-deleted extraction.
            // Use window.exelearningContentBase (set by the page's inline script)
            // so the playground SW scope prefix is preserved.
            // Only the edited media's viewer: a public item page can show
            // several eXeLearning media, each with its own extraction.
            var viewer = document.getElementById('exelearning-viewer-' + this.currentMediaId);
            if (data.contentPath && viewer) {
                var base = window.exelearningContentBase || window.location.origin;
                var url = base + data.contentPath;
                viewer.querySelectorAll('[data-exe-content-path]').forEach(function(el) {
                    el.setAttribute('data-exe-content-path', data.contentPath);
                    if (el.tagName === 'IFRAME') {
                        el.src = url;
                    } else {
                        el.href = url;
                    }
                });
            }
            // Always close the modal after a successful save — avoid
            // window.location.reload() which 404s in PHP-WASM playground.
            this.close();
        }
    };

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            ExeLearningEditor.init();
        });
    } else {
        ExeLearningEditor.init();
    }

    // Expose globally
    window.ExeLearningEditor = ExeLearningEditor;

})();
