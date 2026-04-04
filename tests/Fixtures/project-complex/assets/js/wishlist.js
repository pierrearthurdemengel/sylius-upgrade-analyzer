$(document).ready(function() {
    // Add to wishlist
    $('.wishlist-add').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var productId = $button.data('product-id');

        $.ajax({
            url: '/wishlist/add/' + productId,
            type: 'POST',
            success: function() {
                $button.find('i').removeClass('outline').addClass('red');
                $button.addClass('active');
            }
        });
    });

    // Remove from wishlist
    $('.wishlist-remove').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var itemId = $button.data('item-id');

        $button.closest('.wishlist-item').fadeOut(300, function() {
            $(this).remove();
        });

        $.ajax({
            url: '/wishlist/remove/' + itemId,
            type: 'DELETE'
        });
    });
});
