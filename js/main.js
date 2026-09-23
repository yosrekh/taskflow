document.addEventListener('DOMContentLoaded', function () {
    // Animate on load
    document.body.classList.add('fade-in');

    // Auto-hide success message after 3 seconds
    const successMsg = document.querySelector('.success');
    if (successMsg) {
        setTimeout(() => {
            successMsg.style.display = 'none';
        }, 3000);
    }
});