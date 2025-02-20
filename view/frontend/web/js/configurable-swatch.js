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
                    if (data.image) {
                        $("#product-image").attr("src", data.image);
                    }
                    if (data.price) {
                        $("#product-price").text(data.price);
                    }
                    if (data.url) {
                        history.pushState({}, "", data.url);
                    }
                }
            });
        });
    });
});
