(function () {
    'use strict';

    function initCredentialsPanel() {
        const panel = document.getElementById('ia-outbound-credentials-panel');
        const fields = document.getElementById('ia-outbound-credentials-fields');
        const changeInput = document.getElementById('ia_outbound_change_credentials');
        const changeButton = document.getElementById('ia-outbound-change-credentials-btn');
        const cancelButton = document.getElementById('ia-outbound-cancel-credentials-btn');

        if (!panel || !fields) {
            return;
        }

        const stored = panel.dataset.stored === '1';
        const fromEnv = panel.dataset.fromEnv === '1';

        function showCredentialFields() {
            fields.classList.remove('is-hidden');
            if (changeInput) {
                changeInput.value = '1';
            }
            if (changeButton) {
                changeButton.hidden = true;
            }
            if (cancelButton) {
                cancelButton.hidden = false;
            }
            const access = fields.querySelector('[name="ia_s3_access_key"]');
            if (access) {
                access.value = '';
                access.focus();
            }
            const secret = fields.querySelector('[name="ia_s3_secret_key"]');
            if (secret) {
                secret.value = '';
            }
        }

        function hideCredentialFields() {
            if (!stored || fromEnv) {
                return;
            }
            fields.classList.add('is-hidden');
            if (changeInput) {
                changeInput.value = '0';
            }
            if (changeButton) {
                changeButton.hidden = false;
            }
            if (cancelButton) {
                cancelButton.hidden = true;
            }
            const access = fields.querySelector('[name="ia_s3_access_key"]');
            if (access) {
                access.value = '';
            }
            const secret = fields.querySelector('[name="ia_s3_secret_key"]');
            if (secret) {
                secret.value = '';
            }
        }

        if (stored && !fromEnv) {
            hideCredentialFields();
        }

        if (changeButton) {
            changeButton.addEventListener('click', function (event) {
                event.preventDefault();
                showCredentialFields();
            });
        }

        if (cancelButton) {
            cancelButton.addEventListener('click', function (event) {
                event.preventDefault();
                hideCredentialFields();
            });
        }
    }

    document.addEventListener('DOMContentLoaded', initCredentialsPanel);
})();
