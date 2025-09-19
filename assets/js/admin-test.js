console.log('TEST: Simple script loaded');

jQuery(document).ready(function($) {
    console.log('TEST: jQuery ready fired');
    
    var scanInput = $('#wbs-scan-input');
    console.log('TEST: Found scan input:', scanInput.length);
    
    if (scanInput.length > 0) {
        console.log('TEST: Setting up input event');
        scanInput.on('input', function() {
            var value = $(this).val().trim();
            console.log('TEST: Input changed:', value);
            
            if (value.length >= 3) {
                console.log('TEST: Would search for:', value);
            }
        });
    }
});