require([
    "jquery"
], function ($) {
    $(document).ready(function () {
        $("#search").on("keyup", function () {
            let query = $(this).val();
            if (query.length >= 3) {
                $.ajax({
                    url: "/fastmagento/search/ajax",
                    type: "GET",
                    data: { q: query },
                    success: function (data) {
                        $("#search-results").html(data);
                    }
                });
            }
        });
    });
});
