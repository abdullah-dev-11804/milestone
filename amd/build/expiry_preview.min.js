define([], function() {
    return {
        init: function(validityMap) {
            function pad(value) {
                return value < 10 ? '0' + value : '' + value;
            }

            function formatDate(date) {
                return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
            }

            function updatePreview() {
                var course = document.getElementById('id_courseid');
                var day = document.getElementById('id_issuedate_day');
                var month = document.getElementById('id_issuedate_month');
                var year = document.getElementById('id_issuedate_year');
                var preview = document.getElementById('id_expirypreview');

                if (!course || !day || !month || !year || !preview) {
                    return;
                }

                var days = parseInt(validityMap[course.value] || 0, 10);
                var issueDate = new Date(parseInt(year.value, 10), parseInt(month.value, 10) - 1, parseInt(day.value, 10));

                if (!days || days <= 0) {
                    preview.textContent = 'Calculated expiry date: No expiry';
                    return;
                }

                var expiryDate = new Date(issueDate.getTime());
                expiryDate.setDate(expiryDate.getDate() + days);
                preview.textContent = 'Calculated expiry date: ' + formatDate(expiryDate) + ' (' + days + ' days)';
            }

            ['id_courseid', 'id_issuedate_day', 'id_issuedate_month', 'id_issuedate_year'].forEach(function(id) {
                var element = document.getElementById(id);
                if (element) {
                    element.addEventListener('change', updatePreview);
                }
            });

            updatePreview();
        }
    };
});
