document.addEventListener('DOMContentLoaded', function () {
    // Animate on load
    document.body.classList.add('fade-in');

    // Example: Auto-hide success message after 3 seconds
    const successMsg = document.querySelector('.success');
    if (successMsg) {
        setTimeout(() => {
            successMsg.style.display = 'none';
        }, 3000);
    }

    // AJAX Task Status Update
    const statusInputs = document.querySelectorAll('.task-status');
    statusInputs.forEach(input => {
        input.addEventListener('change', function () {
            const taskId = this.dataset.taskId;
            const newStatus = this.value;

            fetch('update-status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `task_id=${taskId}&status=${newStatus}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    alert('تم تحديث الحالة!');
                }
            });
        });
    });
});

document.addEventListener('DOMContentLoaded', function () {
    // Handle live status update
    const statusInputs = document.querySelectorAll('.task-status');
    statusInputs.forEach(input => {
        input.addEventListener('change', function () {
            const taskId = this.dataset.taskId;
            const newStatus = this.value;

            fetch('../../tasks/update-status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `task_id=${taskId}&status=${newStatus}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    alert('تم تحديث الحالة!');
                } else {
                    alert('فشل في تحديث الحالة.');
                }
            });
        });
    });
});