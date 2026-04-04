$(document).ready(function() {
    $('.product-gallery').on('click', '.thumbnail', function() {
        var imageUrl = $(this).data('large-url');
        $('#main-image').attr('src', imageUrl);
    });
});
