(function ($) {
  function toNum(v) {
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }

  function formatMoney(n) {
    return (Math.round(n * 100) / 100).toFixed(2);
  }

  function formatRatio(n) {
    return (Math.round(n * 1000000) / 1000000).toFixed(6);
  }

  function recalcRowTotals() {
    $("#kpi-activity-grid tr.kpi-metric-row").each(function () {
      var $row = $(this);
      var type = $row.data("type");
      var sum = 0;

      $row.find("input.kpi-input:enabled").each(function () {
        sum += toNum($(this).val());
      });

      var out = type === "money" ? formatMoney(sum) : String(Math.round(sum));
      $row.find(".kpi-row-total").text(out);
    });
  }

  function recalcSummary() {
    // Pull totals directly from grid row totals so it updates live
    var leadKeys = [
      "leads_website",
      "leads_google_ads",
      "leads_houzz",
      "leads_facebook",
      "leads_referrals",
      "leads_repeat_customers",
      "leads_instagram",
      "leads_walkins",
      "leads_other_1",
      "leads_other_2",
    ];

    function rowTotal(key) {
      var $t = $('.kpi-row-total[data-row-total="' + key + '"]');
      if (!$t.length) return 0;
      return toNum($t.text().replace(/,/g, ""));
    }

    var leads = 0;
    leadKeys.forEach(function (k) {
      leads += rowTotal(k);
    });

    var calls = rowTotal("calls");
    var apps = rowTotal("appointments");
    var quotes = rowTotal("quotes");
    var quoteVal = rowTotal("quote_value");
    var sales = rowTotal("sales");
    var salesVal = rowTotal("sales_value");

    // Write to summary numbers (if present)
    var $sum = $("#kpi-activity-summary");
    if (!$sum.length) return;

    $sum.find('[data-kpi="total_leads"]').text(String(Math.round(leads)));
    $sum.find('[data-kpi="calls"]').text(String(Math.round(calls)));
    $sum.find('[data-kpi="appointments"]').text(String(Math.round(apps)));
    $sum.find('[data-kpi="quotes"]').text(String(Math.round(quotes)));
    $sum.find('[data-kpi="quote_value"]').text(formatMoney(quoteVal));
    $sum.find('[data-kpi="sales"]').text(String(Math.round(sales)));
    $sum.find('[data-kpi="sales_value"]').text(formatMoney(salesVal));

    // Stats
    var avgQuote = quotes > 0 ? quoteVal / quotes : 0;
    var avgSale = sales > 0 ? salesVal / sales : 0;

    var callsFromLeads = leads > 0 ? calls / leads : 0;
    var appsFromCalls = calls > 0 ? apps / calls : 0;
    var appsFromLeads = leads > 0 ? apps / leads : 0;
    var quotesFromApps = apps > 0 ? quotes / apps : 0;
    var salesFromQuotes = quotes > 0 ? sales / quotes : 0;
    var salesFromCalls = calls > 0 ? sales / calls : 0;
    var salesFromLeads = leads > 0 ? sales / leads : 0;

    $sum.find('[data-kpi="avg_quote"]').text(formatMoney(avgQuote));
    $sum.find('[data-kpi="avg_sale"]').text(formatMoney(avgSale));

    $sum
      .find('[data-kpi="calls_from_leads"]')
      .text(formatRatio(callsFromLeads));
    $sum.find('[data-kpi="apps_from_calls"]').text(formatRatio(appsFromCalls));
    $sum.find('[data-kpi="apps_from_leads"]').text(formatRatio(appsFromLeads));
    $sum
      .find('[data-kpi="quotes_from_apps"]')
      .text(formatRatio(quotesFromApps));
    $sum
      .find('[data-kpi="sales_from_quotes"]')
      .text(formatRatio(salesFromQuotes));
    $sum
      .find('[data-kpi="sales_from_calls"]')
      .text(formatRatio(salesFromCalls));
    $sum
      .find('[data-kpi="sales_from_leads"]')
      .text(formatRatio(salesFromLeads));
  }

  function recalcAll() {
    recalcRowTotals();
    recalcSummary();
  }

  $(document).on("input", "#kpi-activity-grid input.kpi-input", function () {
    recalcAll();
  });

  $(function () {
    recalcAll();
  });
})(jQuery);
