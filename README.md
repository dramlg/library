# library

```js
window.initDeltaDialog = function($modalElem) {
    $modalElem
        .on('click', '.btn-delta-show-content', function(e) {
            $(this).closest('.delta-section').find('.delta-file-content-group').toggle();
        })
        .on('click', '.btn-delta-sync', function(e) {
            var section = $(this).closest('.delta-section');
            var syncUrl = section.data('sync-url');
            var delta = section.data('delta');
            var direction = $(this).data('direction');
            var data = {
                delta: delta,
                direction: direction
            };

            section.find('.delta-loader').show();

            $.ajax(syncUrl, {
                type: 'POST',
                contentType: 'application/json',
                dataType: "JSON",
                data: JSON.stringify(data)
            })
                .success(function() {
                    section.find('.delta-loader').hide();
                    section.find('.delta-success-sign').show();
                    section.find('.btn-delta-show-content').hide();
                    section.find('.delta-file-content-group').hide();
                })
                .fail(function(error) {
                    var warning = $('<div>').addClass('alert-error')
                        .append($('<p>').text('The configuration file could not be synchronized to the server.').css({'margin-bottom': '0px'}));

                    var confirmationScreen = $('#delta-confirmation_screen');
                    confirmationScreen.find('.delta-payload').html('').append(warning);
                    confirmationScreen.show();
                    section.find('.delta-loader').hide();
                    section.find('.delta-fail-sign').show();
                    section.find('.btn-delta-show-content').hide();
                    section.find('.delta-file-content-group').hide();
                });
        })

    ;
};

```

[link](http://)
