require([
    "jquery"
], function ($) {
    $(document).ready(function () {
        $(".configurable-option").on("change", function () {
            let selectedOptions = {};

            $(".configurable-option").each(function () {
                let name = $(this).attr("name");
                let value = $(this).val();
                selectedOptions[name] = value;
            });

            $.ajax({
                url: "/fastmagento/configurable/ajax",
                type: "GET",
                data: selectedOptions,
                success: function (data) {
                    $("#product-image").attr("src", data.image);
                    $("#product-price").text(data.price);
                    $("#product-name").text(data.name);
                    window.history.pushState({}, "", data.url);
                }
            });
        });
    });
});
