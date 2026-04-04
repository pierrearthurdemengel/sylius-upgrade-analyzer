$(document).ready(function() {
    // Initialize Semantic UI components
    $('.ui.dropdown').dropdown();
    $('.ui.accordion').accordion();
    $('.ui.sidebar').sidebar('attach events', '.toc.item');

    // Flash message auto-dismiss
    setTimeout(function() {
        $('.ui.message .close').trigger('click');
    }, 5000);

    // Product gallery
    $('.product-gallery').on('click', '.thumbnail', function() {
        var imageUrl = $(this).data('large-url');
        $('#main-image').attr('src', imageUrl);
        $('.thumbnail').removeClass('active');
        $(this).addClass('active');
    });

    // Newsletter subscription
    $('#newsletter-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var email = $form.find('input[name="email"]').val();

        $.ajax({
            url: $form.attr('action'),
            type: 'POST',
            data: { email: email },
            success: function(response) {
                $form.find('.result').html('<div class="ui positive message">' + response.message + '</div>');
            },
            error: function(xhr) {
                $form.find('.result').html('<div class="ui negative message">' + xhr.responseJSON.message + '</div>');
            }
        });
    });

    // Lazy loading for images
    $('img[data-src]').each(function() {
        var $img = $(this);
        $img.attr('src', $img.data('src'));
    });
});
