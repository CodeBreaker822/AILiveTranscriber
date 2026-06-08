function toggleLoading(button, isLoading) {
    if (isLoading) {
        if (!button.data('original-html')) {
            button.data('original-html', button.html());
        }

        button.html(`
            <span class="flex items-center justify-center">
                <svg class="animate-spin -ml-1 mr-3 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Processing...</span>
            </span>
        `);
    } else {
        const originalHtml = button.data('original-html');

        if (originalHtml) {
            button.html(originalHtml);
            button.removeData('original-html');
        }
    }

    button.prop('disabled', isLoading);
}

// Document ready handlers for CRUD operations
$(document).ready(function() {
    // Global AJAX error handling
    $(document).ajaxError(function(event, xhr, settings, error) {
        if (xhr.responseJSON && xhr.responseJSON.errors) {
            const errors = Object.values(xhr.responseJSON.errors).flat();
            errors.forEach(error => showNotification(error, 'error'));
        } else if (xhr.responseJSON && xhr.responseJSON.message) {
            showNotification(xhr.responseJSON.message, 'error');
        } else {
            showNotification('An error occurred. Please try again.', 'error');
        }
    });
});
