/**
 * assets/js/register.js
 *
 * Registration page interactive logic:
 * - Client-side validation & live password strength meter
 * - Password visibility toggle
 * - Asynchronous form submission via Fetch API to /api/auth.php
 * - Error rendering & auto-redirect on success
 */

'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const config = window.SnapLink || {};
    const apiUrl = config.apiUrl || 'api/auth.php';
    const csrfToken = config.csrf || '';

    // Elements
    const form          = document.getElementById('register-form');
    const usernameInput = document.getElementById('username');
    const emailInput    = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const togglePassBtn = document.getElementById('toggle-password');
    const eyeOpen       = document.getElementById('eye-open');
    const eyeClosed     = document.getElementById('eye-closed');

    const strengthBar   = document.getElementById('strength-bar');
    const strengthLabel = document.getElementById('password-strength-label');

    const usernameError = document.getElementById('username-error');
    const emailError    = document.getElementById('email-error');
    const passwordError = document.getElementById('password-error');

    const globalError   = document.getElementById('global-error');
    const globalErrorList = document.getElementById('global-error-list');
    const globalSuccess = document.getElementById('global-success');
    const globalSuccessText = document.getElementById('global-success-text');

    const submitBtn     = document.getElementById('register-btn');
    const submitText    = document.getElementById('register-btn-text');
    const submitSpinner = document.getElementById('register-btn-spinner');
    const submitIcon    = document.getElementById('register-btn-icon');

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

    // ── Password strength evaluator ──────────────────────────────────────────
    function assessPasswordStrength(pass) {
        if (!pass) return { score: 0, text: '', color: 'bg-transparent', width: '0%' };

        let score = 0;
        if (pass.length >= 8) score++;
        if (pass.length >= 12) score++;
        if (/[A-Z]/.test(pass)) score++;
        if (/[0-9]/.test(pass)) score++;
        if (/[^A-Za-z0-9]/.test(pass)) score++;

        switch (score) {
            case 1:
                return { score: 1, text: 'Very weak', color: 'bg-red-500', width: '20%' };
            case 2:
                return { score: 2, text: 'Weak', color: 'bg-amber-500', width: '40%' };
            case 3:
                return { score: 3, text: 'Fair', color: 'bg-yellow-400', width: '60%' };
            case 4:
                return { score: 4, text: 'Good', color: 'bg-emerald-400', width: '80%' };
            case 5:
            default:
                return { score: 5, text: 'Strong', color: 'bg-emerald-500', width: '100%' };
        }
    }

    if (passwordInput && strengthBar && strengthLabel) {
        passwordInput.addEventListener('input', () => {
            const val = passwordInput.value;
            const res = assessPasswordStrength(val);
            strengthBar.className = `strength-bar h-full rounded-full ${res.color}`;
            strengthBar.style.width = res.width;
            strengthLabel.textContent = val.length ? `Strength: ${res.text}` : '';
        });
    }

    // ── Validation helpers ───────────────────────────────────────────────────
    function clearErrors() {
        if (globalError) globalError.classList.add('hidden');
        if (globalErrorList) globalErrorList.innerHTML = '';
        if (globalSuccess) globalSuccess.classList.add('hidden');

        [usernameInput, emailInput, passwordInput].forEach(input => {
            if (input) {
                input.classList.remove('error', 'success');
            }
        });

        [usernameError, emailError, passwordError].forEach(p => {
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

    function showGlobalErrors(errors) {
        if (!globalError || !globalErrorList) return;
        globalErrorList.innerHTML = '';
        const list = Array.isArray(errors) ? errors : [errors];
        list.forEach(err => {
            const li = document.createElement('li');
            li.textContent = err;
            globalErrorList.appendChild(li);
        });
        globalError.classList.remove('hidden');
    }

    function setLoading(isLoading) {
        submitBtn.disabled = isLoading;
        submitSpinner.classList.toggle('hidden', !isLoading);
        submitIcon.classList.toggle('hidden', isLoading);
        submitText.textContent = isLoading ? 'Creating account...' : 'Create my account';
    }

    // ── Form submission ──────────────────────────────────────────────────────
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors();

        const username = usernameInput.value.trim();
        const email    = emailInput.value.trim();
        const password = passwordInput.value;

        let hasLocalError = false;

        // Validation: Username
        if (!username) {
            showFieldError(usernameInput, usernameError, 'Please enter a username.');
            hasLocalError = true;
        } else if (username.length < 3 || username.length > 50) {
            showFieldError(usernameInput, usernameError, 'Username must be 3–50 characters.');
            hasLocalError = true;
        } else if (!/^[a-zA-Z0-9_-]+$/.test(username)) {
            showFieldError(usernameInput, usernameError, 'Only letters, numbers, hyphens, and underscores.');
            hasLocalError = true;
        }

        // Validation: Email
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!email) {
            showFieldError(emailInput, emailError, 'Please enter an email address.');
            hasLocalError = true;
        } else if (!emailRegex.test(email)) {
            showFieldError(emailInput, emailError, 'Please enter a valid email address.');
            hasLocalError = true;
        }

        // Validation: Password
        if (!password) {
            showFieldError(passwordInput, passwordError, 'Please enter a password.');
            hasLocalError = true;
        } else if (password.length < 8) {
            showFieldError(passwordInput, passwordError, 'Password must be at least 8 characters.');
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
                    action:   'register',
                    _csrf:    csrfToken,
                    username: username,
                    email:    email,
                    password: password,
                }),
            });

            const data = await response.json().catch(() => null);

            if (!data) {
                showGlobalErrors(['Server returned an invalid response. Please try again.']);
                setLoading(false);
                return;
            }

            if (response.ok && data.success) {
                // Success
                if (globalSuccess && globalSuccessText) {
                    globalSuccessText.textContent = data.message || 'Account created! Redirecting...';
                    globalSuccess.classList.remove('hidden');
                }
                setTimeout(() => {
                    window.location.href = data.redirect || 'index.php';
                }, 1000);
            } else {
                // Errors
                setLoading(false);
                if (data.errors && Array.isArray(data.errors)) {
                    showGlobalErrors(data.errors);
                } else if (data.message) {
                    showGlobalErrors([data.message]);
                } else {
                    showGlobalErrors(['Registration failed. Please check your inputs.']);
                }
            }
        } catch (err) {
            setLoading(false);
            showGlobalErrors(['Network error. Please check your connection and try again.']);
        }
    });
});
