$(document).ready(function() {
    // Initialize DataTables on admin grids
    if ($.fn.DataTable) {
        $('.sylius-grid-table').DataTable({
            paging: true,
            searching: true,
            ordering: true,
            pageLength: 25,
            language: {
                search: 'Rechercher :',
                lengthMenu: 'Afficher _MENU_ par page',
                info: '_START_ - _END_ sur _TOTAL_',
                paginate: {
                    previous: 'Precedent',
                    next: 'Suivant'
                }
            }
        });
    }

    // Confirm delete modals
    $('[data-requires-confirmation]').on('click', function(e) {
        e.preventDefault();
        var $trigger = $(this);
        var message = $trigger.data('confirmation-message') || 'Are you sure?';

        $('#confirmation-modal .content p').text(message);
        $('#confirmation-modal')
            .modal({
                closable: false,
                onApprove: function() {
                    window.location.href = $trigger.attr('href');
                }
            })
            .modal('show');
    });

    // Bulk actions
    $('#select-all').on('change', function() {
        var isChecked = $(this).is(':checked');
        $('input[name="ids[]"]').prop('checked', isChecked);
        updateBulkActionsVisibility();
    });

    $('input[name="ids[]"]').on('change', function() {
        updateBulkActionsVisibility();
    });

    function updateBulkActionsVisibility() {
        var checkedCount = $('input[name="ids[]"]:checked').length;
        if (checkedCount > 0) {
            $('.bulk-actions').show();
            $('.bulk-actions .count').text(checkedCount);
        } else {
            $('.bulk-actions').hide();
        }
    }

    // Sortable lists
    if ($.fn.sortable) {
        $('.sortable-list').sortable({
            handle: '.sortable-handle',
            update: function(event, ui) {
                var positions = {};
                $(this).find('[data-id]').each(function(index) {
                    positions[$(this).data('id')] = index;
                });

                $.ajax({
                    url: $(this).data('sort-url'),
                    type: 'PUT',
                    data: { positions: positions }
                });
            }
        });
    }
});
