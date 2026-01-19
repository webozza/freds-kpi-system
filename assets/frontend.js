(function ($) {
  // ---------- helpers ----------
  function toNum(v) {
    if (v === null || v === undefined || v === "") return 0;
    var n = parseFloat(String(v).replace(/,/g, "").replace("$", ""));
    return isNaN(n) ? 0 : n;
  }

  function clamp0(n) {
    return n < 0 ? 0 : n;
  }

  function fmtMoney(n) {
    n = clamp0(n);
    var fixed = (Math.round(n * 100) / 100).toFixed(
      kpiFront?.moneyDecimals ?? 2
    );
    var parts = fixed.split(".");
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    return (kpiFront?.currencySymbol ?? "$") + parts.join(".");
  }

  function fmtInt(n) {
    n = clamp0(n);
    return String(Math.round(n));
  }

  function fmtPct(ratio) {
    ratio = clamp0(ratio);
    var pct = ratio * 100;
    return pct.toFixed(kpiFront?.percentDecimals ?? 2) + "%";
  }

  function readJson(id) {
    var el = document.getElementById(id);
    if (!el) return null;
    try {
      return JSON.parse(el.textContent || "null");
    } catch (e) {
      return null;
    }
  }

  function buildUrlWithYm(ym) {
    var u = new URL(window.location.href);
    u.searchParams.set("kpi_ym", ym);
    if (!u.searchParams.get("kpi_tab"))
      u.searchParams.set("kpi_tab", "activity");
    window.location.href = u.toString();
  }

  // ---------- flatpickr month picker ----------
  function initMonthPicker() {
    var el = document.getElementById("kpiMonthPicker");
    if (!el || !window.flatpickr) return;

    var currentYm =
      el.value && /^\d{4}-\d{2}$/.test(el.value)
        ? el.value
        : kpiFront?.todayYm ?? "";
    var defaultDate = currentYm ? currentYm + "-01" : null;

    flatpickr(el, {
      dateFormat: "Y-m",
      altInput: true,
      altFormat: "F Y",
      defaultDate: defaultDate,
      onChange: function (_selectedDates, dateStr) {
        if (dateStr && /^\d{4}-\d{2}$/.test(dateStr)) buildUrlWithYm(dateStr);
      },
    });
  }

  // ---------- KPI card recalcs ----------
  function recalcCardsFromHot(hot, rows, daysInMonth, leadKeys) {
    function rowKeyToIndex(key) {
      for (var i = 0; i < rows.length; i++) if (rows[i].key === key) return i;
      return -1;
    }

    function sumRowByKey(key) {
      var idx = rowKeyToIndex(key);
      if (idx < 0) return 0;
      var sum = 0;
      for (var d = 1; d <= daysInMonth; d++)
        sum += toNum(hot.getDataAtCell(idx, d));
      return sum;
    }

    var leads = 0;
    (leadKeys || []).forEach(function (k) {
      leads += sumRowByKey(k);
    });

    var calls = sumRowByKey("calls");
    var apps = sumRowByKey("appointments");
    var quotes = sumRowByKey("quotes");
    var quoteVal = sumRowByKey("quote_value");
    var sales = sumRowByKey("sales");
    var salesVal = sumRowByKey("sales_value");

    var avgQuote = quotes > 0 ? quoteVal / quotes : 0;
    var avgSale = sales > 0 ? salesVal / sales : 0;

    var callsFromLeads = leads > 0 ? calls / leads : 0;
    var appsFromCalls = calls > 0 ? apps / calls : 0;
    var appsFromLeads = leads > 0 ? apps / leads : 0;
    var quotesFromApps = apps > 0 ? quotes / apps : 0;
    var salesFromQuotes = quotes > 0 ? sales / quotes : 0;
    var salesFromCalls = calls > 0 ? sales / calls : 0;
    var salesFromLeads = leads > 0 ? sales / leads : 0;

    function setText(id, txt) {
      var el = document.getElementById(id);
      if (el) el.textContent = txt;
    }

    setText("kpi_total_leads", fmtInt(leads));
    setText("kpi_total_calls", fmtInt(calls));
    setText("kpi_total_apps", fmtInt(apps));
    setText("kpi_total_quotes", fmtInt(quotes));
    setText("kpi_total_quote_val", fmtMoney(quoteVal));
    setText("kpi_total_sales", fmtInt(sales));
    setText("kpi_total_sales_val", fmtMoney(salesVal));

    setText("kpi_avg_quote", fmtMoney(avgQuote));
    setText("kpi_avg_sale", fmtMoney(avgSale));

    setText("kpi_calls_from_leads", fmtPct(callsFromLeads));
    setText("kpi_apps_from_calls", fmtPct(appsFromCalls));
    setText("kpi_apps_from_leads", fmtPct(appsFromLeads));
    setText("kpi_quotes_from_apps", fmtPct(quotesFromApps));
    setText("kpi_sales_from_quotes", fmtPct(salesFromQuotes));
    setText("kpi_sales_from_calls", fmtPct(salesFromCalls));
    setText("kpi_sales_from_leads", fmtPct(salesFromLeads));
  }

  // ---------- Handsontable: Activity ----------
  function initHotActivity() {
    var container = document.getElementById("kpiHotActivity");
    if (!container || !window.Handsontable) return;

    var rows = readJson("kpi_activity_rows") || [];
    var prefill = readJson("kpi_activity_prefill") || [];
    var meta = readJson("kpi_activity_meta") || {};
    var daysInMonth = meta.daysInMonth || 30;
    var totalCol = daysInMonth + 1; // col index of Total (0=Metric, 1..days, total=days+1)

    // build columns
    var colHeaders = ["Metric"];
    for (var d = 1; d <= daysInMonth; d++) colHeaders.push(String(d));
    colHeaders.push("Total");

    // data rows: [Label, ...days..., Total]
    var data = rows.map(function (r, i) {
      var dayVals = (prefill[i] || []).slice(0, daysInMonth).map(function (v) {
        var n = toNum(v);
        return r.type === "money" ? Math.round(n * 100) / 100 : Math.round(n);
      });

      var sum = dayVals.reduce(function (a, b) {
        return a + toNum(b);
      }, 0);
      var total = r.type === "money" ? fmtMoney(sum) : fmtInt(sum);

      return [r.label].concat(dayVals).concat([total]);
    });

    function firstColRenderer(instance, td, row, col, prop, value) {
      Handsontable.renderers.TextRenderer.apply(this, arguments);
      td.style.fontWeight = "800";
      td.style.whiteSpace = "nowrap";
      td.classList.add("kpi-hot-metric");

      var group = rows[row]?.group || "";
      if (group === "Leads") td.classList.add("kpi-hot-metric--leads");
      if (group === "Pipeline") td.classList.add("kpi-hot-metric--pipe");
    }

    function totalRenderer(instance, td, row, col, prop, value) {
      Handsontable.renderers.TextRenderer.apply(this, arguments);
      td.style.fontWeight = "900";
      td.classList.add("kpi-hot-total");
    }

    function numRenderer(instance, td, row, col, prop, value) {
      Handsontable.renderers.NumericRenderer.apply(this, arguments);
      td.classList.add("kpi-hot-num");
    }

    function recalcRowTotal(hot, rowIndex) {
      var r = rows[rowIndex] || {};
      var sum = 0;
      for (var d = 1; d <= daysInMonth; d++)
        sum += toNum(hot.getDataAtCell(rowIndex, d));
      return r.type === "money" ? fmtMoney(sum) : fmtInt(sum);
    }

    var hot = new Handsontable(container, {
      data: data,
      colHeaders: colHeaders,
      rowHeaders: true,
      height: "auto",
      licenseKey: "non-commercial-and-evaluation",
      fixedColumnsStart: 1,
      manualColumnResize: true,
      manualRowResize: true,
      stretchH: "none",
      contextMenu: true,
      dropdownMenu: true,
      filters: true,
      outsideClickDeselects: false,
      columnSorting: false,
      minSpareRows: 0,
      cells: function (row, col) {
        var cp = {};
        var isLabelCol = col === 0;
        var isTotalCol = col === totalCol;

        if (isLabelCol) {
          cp.readOnly = true;
          cp.renderer = firstColRenderer;
          return cp;
        }
        if (isTotalCol) {
          cp.readOnly = true;
          cp.renderer = totalRenderer;
          return cp;
        }

        cp.type = "numeric";
        cp.numericFormat = { pattern: "0.[00]" };
        cp.renderer = numRenderer;
        return cp;
      },

      afterChange: function (changes, source) {
        // ✅ CRITICAL: ignore our own total writes
        if (!changes || source === "loadData" || source === "calc") return;

        // collect only rows that were edited in day columns
        var touched = new Set();
        changes.forEach(function (c) {
          var row = c[0];
          var col = c[1];
          if (col >= 1 && col <= daysInMonth) touched.add(row);
        });

        // batch updates to avoid cascading events
        hot.batch(function () {
          touched.forEach(function (r) {
            hot.setDataAtCell(r, totalCol, recalcRowTotal(hot, r), "calc");
          });
        });

        recalcCardsFromHot(hot, rows, daysInMonth, meta.selectedLeadKeys || []);
      },
    });

    // initial totals + cards (batch to avoid any weirdness)
    hot.batch(function () {
      for (var i = 0; i < rows.length; i++) {
        hot.setDataAtCell(i, totalCol, recalcRowTotal(hot, i), "calc");
      }
    });
    recalcCardsFromHot(hot, rows, daysInMonth, meta.selectedLeadKeys || []);

    // submit -> build payload JSON from grid
    var form = document.getElementById("kpiActivityForm");
    if (form) {
      form.addEventListener("submit", function () {
        var ym = meta.ym || (kpiFront?.todayYm ?? "");
        var y = parseInt(ym.slice(0, 4), 10);
        var m = parseInt(ym.slice(5, 7), 10);

        var payload = { kpi: {} };

        for (var d = 1; d <= daysInMonth; d++) {
          var dd = String(d).padStart(2, "0");
          var mm = String(m).padStart(2, "0");
          var date = y + "-" + mm + "-" + dd;

          payload.kpi[date] = {};

          rows.forEach(function (r, i) {
            var v = toNum(hot.getDataAtCell(i, d));
            payload.kpi[date][r.key] =
              r.type === "money" ? Math.round(v * 100) / 100 : Math.round(v);
          });
        }

        var hidden = document.getElementById("kpi_payload");
        if (hidden) hidden.value = JSON.stringify(payload);
      });
    }
  }

  // ---------- Handsontable: Monthly (read-only) ----------
  function initHotMonthly() {
    var container = document.getElementById("kpiHotMonthly");
    if (!container || !window.Handsontable) return;

    var grid = readJson("kpi_monthly_grid") || [];
    if (!grid.length) return;

    var data = grid.map(function (r) {
      return r.slice(0, r.length - 1);
    });
    var emphasis = grid.map(function (r) {
      return r[r.length - 1] === "__emphasis__";
    });

    var colHeaders = [
      "Metric",
      "Jan",
      "Feb",
      "Mar",
      "Apr",
      "May",
      "Jun",
      "Jul",
      "Aug",
      "Sep",
      "Oct",
      "Nov",
      "Dec",
      "YTD",
      "Avg",
    ];

    function metricRenderer(instance, td, row) {
      Handsontable.renderers.TextRenderer.apply(this, arguments);
      td.style.fontWeight = emphasis[row] ? "900" : "800";
      td.classList.add("kpi-hot-metric");
      if (emphasis[row]) td.classList.add("kpi-hot-metric--emph");
    }

    function cellRenderer(instance, td, row) {
      Handsontable.renderers.TextRenderer.apply(this, arguments);
      td.classList.add("kpi-hot-num");
      if (emphasis[row]) td.style.fontWeight = "800";
    }

    new Handsontable(container, {
      data: data,
      colHeaders: colHeaders,
      rowHeaders: true,
      height: "auto",
      readOnly: true,
      licenseKey: "non-commercial-and-evaluation",
      fixedColumnsStart: 1,
      manualColumnResize: true,
      stretchH: "none",
      cells: function (row, col) {
        var cp = { readOnly: true };
        cp.renderer = col === 0 ? metricRenderer : cellRenderer;
        return cp;
      },
    });
  }

  // ---------- init ----------
  $(function () {
    initMonthPicker();
    initHotActivity();
    initHotMonthly();
  });
})(jQuery);
