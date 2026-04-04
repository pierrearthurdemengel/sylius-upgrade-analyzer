$(document).ready(function() {
    // Update cart item quantity
    $('.cart-quantity input').on('change', function() {
        var $input = $(this);
        var itemId = $input.data('item-id');
        var quantity = $input.val();

        $.ajax({
            url: '/cart/items/' + itemId,
            type: 'PATCH',
            data: { quantity: quantity },
            success: function(response) {
                $('.cart-total').text(response.total);
                $('.cart-items-count').text(response.itemsCount);
            }
        });
    });

    // Remove cart item
    $('.cart-remove').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var itemId = $button.data('item-id');

        $.ajax({
            url: '/cart/items/' + itemId,
            type: 'DELETE',
            success: function() {
                $button.closest('tr').fadeOut(300, function() {
                    $(this).remove();
                });
            }
        });
    });
});
