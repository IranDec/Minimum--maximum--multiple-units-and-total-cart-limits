/**
 * mbmaxlimit front-end JS
 */
$(document).ready(function () {
    if (typeof prestashop === 'undefined') {
        return;
    }

    function showModal(message) {
        // Simple modal implementation
        $('body').append('<div id="mbmaxlimit-modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000;"></div>');
        $('body').append('<div id="mbmaxlimit-modal-content" style="position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:20px; border-radius:5px; z-index:10001;">' + message + '<br><br><button id="mbmaxlimit-modal-close" class="btn btn-primary">OK</button></div>');

        $('#mbmaxlimit-modal-close, #mbmaxlimit-modal-overlay').on('click', function() {
            $('#mbmaxlimit-modal-overlay, #mbmaxlimit-modal-content').remove();
        });
    }

    prestashop.on('updatedProduct', function (event) {
        // Handle remaining quantity display
        if (event && event.product_details && typeof event.product_details.mbmaxlimit_remaining_message !== 'undefined') {
            const message = event.product_details.mbmaxlimit_remaining_message;
            const displayDiv = $('#mbmaxlimit-remaining-qty-display');
            if (displayDiv.length) {
                if (message) {
                    displayDiv.html('<div class="alert alert-info">' + message + '</div>');
                } else {
                    displayDiv.empty();
                }
            }
        }
    });

    prestashop.on('updateCart', function (event) {
        // Handle modal error display
        if (typeof mbmaxlimit_use_modal === 'undefined' || !mbmaxlimit_use_modal) {
            return;
        }

        if (event && event.resp && event.resp.errors && event.resp.errors.length > 0) {
            let error_to_show = '';
            // Patterns to match our module's errors
            const pattern1 = mbmaxlimit_error_pattern.replace('%d', '\\d+');
            const pattern2 = mbmaxlimit_error_pattern2;

            const errorRegex1 = new RegExp(pattern1);

            let newErrors = [];
            let foundOurError = false;

            event.resp.errors.forEach(function(error) {
                if (error.match(errorRegex1) || error.indexOf(pattern2) !== -1) {
                    error_to_show = error; // Capture the specific error message
                    foundOurError = true;
                } else {
                    newErrors.push(error); // Keep other errors
                }
            });

            if (foundOurError) {
                showModal(error_to_show);
                // Prevent PrestaShop's default error display by removing our error from the list
                event.resp.errors = newErrors;
            }
        }
    });

    // Trigger initial display for remaining quantity on page load
    if (typeof mbmaxlimit_init_data !== 'undefined' && mbmaxlimit_init_data.message) {
        const displayDiv = $('#mbmaxlimit-remaining-qty-display');
        if (displayDiv.length) {
            displayDiv.html('<div class="alert alert-info">' + mbmaxlimit_init_data.message + '</div>');
        }
    }
});
