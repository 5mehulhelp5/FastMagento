require([
    "jquery"
], function ($) {
    $(document).ready(function () {
        $(".filter-option").on("change", function () {
            let filters = {};

            $(".filter-option").each(function () {
                let name = $(this).attr("name");
                let value = $(this).val();
                if (value) {
                    filters[name] = value;
                }
            });

            $.ajax({
                url: "/fastmagento/filter/ajax",
                type: "GET",
                data: filters,
                success: function (data) {
                    $("#product-list").html(data);
                }
            });
        });
    });
});
