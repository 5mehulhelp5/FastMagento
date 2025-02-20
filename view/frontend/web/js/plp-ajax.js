require([
    "jquery"
], function ($) {
    $(document).ready(function () {
        function updatePLP() {
            let filters = {};
            $(".filter-option").each(function () {
                let name = $(this).attr("name");
                let value = $(this).val();
                if (value) {
                    filters[name] = value;
                }
            });

            let sorting = $("#sorting-options").val();
            let page = $(".pagination .active").data("page") || 1;

            $.ajax({
                url: "/fastmagento/plp/ajax",
                type: "GET",
                data: { filters: filters, sorting: sorting, page: page },
                success: function (data) {
                    $("#product-list").html(data.products);
                    $(".pagination").html(data.pagination);
                }
            });
        }

        $(".filter-option, #sorting-options").on("change", updatePLP);
        $(".pagination").on("click", "a", function (e) {
            e.preventDefault();
            $(".pagination .active").removeClass("active");
            $(this).addClass("active");
            updatePLP();
        });
    });
});
