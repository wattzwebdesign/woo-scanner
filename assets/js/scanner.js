jQuery(document).ready(function($) {
    if (typeof wbs_ajax === 'undefined') {
        return;
    }
    
    $(document).on('keydown', function(e) {
        if (e.ctrlKey && e.key === '/') {
            e.preventDefault();
            $('#wbs-scan-input').focus();
        }
    });
});