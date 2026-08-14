// Client Login Verify - client facing OTP verification interactions
(function () {
    'use strict';

    var input = document.getElementById('clv_otp');
    if (!input) {
        return;
    }

    // Auto-focus the code field
    input.focus();

    // Keep only digits as the user types
    input.addEventListener('input', function () {
        var cleaned = input.value.replace(/\D/g, '');
        if (cleaned !== input.value) {
            input.value = cleaned;
        }
    });

    // Submit automatically once the code reaches the expected length
    var max = parseInt(input.getAttribute('maxlength'), 10) || 6;
    input.addEventListener('keyup', function () {
        if (input.value.length === max) {
            var form = input.closest('form');
            if (form) {
                form.submit();
            }
        }
    });
})();
