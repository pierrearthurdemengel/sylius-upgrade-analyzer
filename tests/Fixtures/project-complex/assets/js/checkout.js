$(document).ready(function() {
    // Toggle billing address form
    var $differentBillingCheckbox = $('#sylius_checkout_address_differentBillingAddress');
    var $billingAddressContainer = $('#sylius-billing-address-container');

    $differentBillingCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            $billingAddressContainer.slideDown();
        } else {
            $billingAddressContainer.slideUp();
        }
    });

    // Province field update on country change
    $('select[name$="[countryCode]"]').on('change', function() {
        var $select = $(this);
        var countryCode = $select.val();
        var $provinceContainer = $select.closest('.fields').find('.province-container');

        if (!countryCode) {
            $provinceContainer.html('');
            return;
        }

        $.ajax({
            url: '/ajax/provinces',
            type: 'GET',
            data: { countryCode: countryCode },
            success: function(response) {
                $provinceContainer.html(response);
            },
            error: function() {
                $provinceContainer.html('');
            }
        });
    });

    // Form validation
    $('.checkout-form').on('submit', function(e) {
        var $form = $(this);
        var isValid = true;

        $form.find('[required]').each(function() {
            var $field = $(this);
            if (!$field.val()) {
                isValid = false;
                $field.closest('.field').addClass('error');
            } else {
                $field.closest('.field').removeClass('error');
            }
        });

        // Email validation
        var $email = $form.find('input[type="email"]');
        if ($email.length && $email.val()) {
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test($email.val())) {
                isValid = false;
                $email.closest('.field').addClass('error');
            }
        }

        if (!isValid) {
            e.preventDefault();
            $('html, body').animate({
                scrollTop: $form.find('.field.error').first().offset().top - 100
            }, 500);
        }
    });

    // Address book selection
    $('.address-book-select').on('click', function(e) {
        e.preventDefault();
        var addressId = $(this).data('address-id');

        $.ajax({
            url: '/ajax/address/' + addressId,
            type: 'GET',
            success: function(address) {
                $('input[name$="[firstName]"]').val(address.firstName);
                $('input[name$="[lastName]"]').val(address.lastName);
                $('input[name$="[street]"]').val(address.street);
                $('input[name$="[city]"]').val(address.city);
                $('input[name$="[postcode]"]').val(address.postcode);
                $('select[name$="[countryCode]"]').val(address.countryCode).trigger('change');
            }
        });
    });
});
