/**
 * assets/js/login.js
 *
 * Login page interactive logic:
 * - Client-side validation
 * - Password visibility toggle
 * - Asynchronous form submission via Fetch API to /api/auth.php
 * - Inline & alert error display
 * - Smooth redirection on success
 */

'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const config      = window.SnapLink || {};
    const apiUrl      = config.apiUrl || 'api/auth.php';
    const csrfToken   = config.csrf || '';
    const defaultDest = config.redirectUrl || 'index.php';

    // Elements
    const form            = document.getElementById('login-form');
    const identifierInput = document.getElementById('identifier');
    const passwordInput   = document.getElementById('password');
    const togglePassBtn   = document.getElementById('toggle-password');
    const eyeOpen         = document.getElementById('eye-open');
    const eyeClosed       = document.getElementById('eye-closed');

    const identifierError = document.getElementById('identifier-error');
    const passwordError   = document.getElementById('password-error');

    const globalError     = document.getElementById('global-error');
    const globalErrorText = document.getElementById('global-error-text');

    const submitBtn       = document.getElementById('login-btn');
    const submitText      = document.getElementById('login-btn-text');
    const submitSpinner   = document.getElementById('login-btn-spinner');
    const submitIcon      = document.getElementById('login-btn-icon');

    if (!form) return;

    // ── Password visibility toggle ───────────────────────────────────────────
    if (togglePassBtn && passwordInput) {
        togglePassBtn.addEventListener('click', () => {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            eyeOpen.classList.toggle('hidden', isPassword);
            eyeClosed.classList.toggle('hidden', !isPassword);
        });
    }

    // ── Reset state ──────────────────────────────────────────────────────────
    function clearErrors() {
        if (globalError) globalError.classList.add('hidden');
        if (globalErrorText) globalErrorText.textContent = '';

        [identifierInput, passwordInput].forEach(input => {
            if (input) input.classList.remove('error');
        });

        [identifierError, passwordError].forEach(p => {
            if (p) {
                p.classList.add('hidden');
                p.textContent = '';
            }
        });
    }

    function showFieldError(input, errorEl, message) {
        if (input) input.classList.add('error');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.classList.remove('hidden');
        }
    }

    function showGlobalError(message) {
        if (!globalError || !globalErrorText) return;
        globalErrorText.textContent = message;
        globalError.classList.remove('hidden');
    }

    function setLoading(isLoading) {
        submitBtn.disabled = isLoading;
        submitSpinner.classList.toggle('hidden', !isLoading);
        submitIcon.classList.toggle('hidden', isLoading);
        submitText.textContent = isLoading ? 'Signing in...' : 'Sign In';
    }

    // ── Form submission via Fetch ────────────────────────────────────────────
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors();

        const identifier = identifierInput.value.trim();
        const password   = passwordInput.value;

        let hasLocalError = false;

        if (!identifier) {
            showFieldError(identifierInput, identifierError, 'Please enter your email or username.');
            hasLocalError = true;
        }

        if (!password) {
            showFieldError(passwordInput, passwordError, 'Please enter your password.');
            hasLocalError = true;
        }

        if (hasLocalError) return;

        setLoading(true);

        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    action:     'login',
                    _csrf:      csrfToken,
                    identifier: identifier,
                    password:   password,
                    redirect:   defaultDest,
                }),
            });

            const data = await response.json().catch(() => null);

            if (!data) {
                showGlobalError('Server returned an unexpected response. Please try again.');
                setLoading(false);
                return;
            }

            if (response.ok && data.success) {
                submitText.textContent = 'Success! Redirecting...';
                const destination = data.redirect || defaultDest;
                setTimeout(() => {
                    window.location.href = destination;
                }, 300);
            } else {
                setLoading(false);
                showGlobalError(data.error || data.message || 'Invalid credentials. Please try again.');
            }
        } catch (err) {
            setLoading(false);
            showGlobalError('Network error. Please check your connection and try again.');
        }
    });
});
