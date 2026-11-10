document.querySelectorAll('[data-batch-start]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }

        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        form.querySelector('[data-batch-pending]').hidden = false;
        if (event.submitter) {
            event.submitter.setAttribute('aria-disabled', 'true');
            event.submitter.textContent = 'Please wait...';
        }
    });
});

if (document.querySelector('[data-refresh-status]')) {
    window.setTimeout(() => window.location.reload(), 3000);
}
