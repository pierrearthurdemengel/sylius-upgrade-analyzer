$(document).ready(function() {
    var $mainImage = $('#main-image');
    var $thumbnails = $('.product-gallery .thumbnail');
    var currentIndex = 0;

    // Thumbnail click handler
    $thumbnails.on('click', function() {
        var $thumb = $(this);
        var imageUrl = $thumb.data('large-url');

        $mainImage.fadeOut(200, function() {
            $mainImage.attr('src', imageUrl).fadeIn(200);
        });

        $thumbnails.removeClass('active bordered');
        $thumb.addClass('active bordered');
        currentIndex = $thumbnails.index($thumb);
    });

    // Keyboard navigation
    $(document).on('keydown', function(e) {
        if ($('.product-gallery').length === 0) return;

        if (e.key === 'ArrowLeft' && currentIndex > 0) {
            currentIndex--;
            $thumbnails.eq(currentIndex).trigger('click');
        } else if (e.key === 'ArrowRight' && currentIndex < $thumbnails.length - 1) {
            currentIndex++;
            $thumbnails.eq(currentIndex).trigger('click');
        }
    });

    // Image zoom on hover
    $mainImage.on('mouseenter', function() {
        $(this).css('cursor', 'zoom-in');
    }).on('click', function() {
        var $overlay = $('<div class="image-zoom-overlay"></div>');
        var $zoomedImage = $('<img>').attr('src', $(this).attr('src')).addClass('zoomed-image');

        $overlay.append($zoomedImage).appendTo('body');
        $overlay.on('click', function() {
            $(this).remove();
        });
    });
});
