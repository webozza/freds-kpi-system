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

  // Store references to both HOT instances for recalc
  var hotLeads = null;
  var hotSales = null;
  var leadsRows = [];
  var salesRows = [];
  var activityMeta = {};

  // ---------- KPI card recalcs ----------
  function recalcAllCards() {
    var daysInMonth = activityMeta.daysInMonth || 30;
    var leadKeys = activityMeta.selectedLeadKeys || [];

    function sumFromHot(hot, rows, key) {
      if (!hot) return 0;
      for (var i = 0; i < rows.length; i++) {
        if (rows[i].key === key) {
          var sum = 0;
          for (var d = 1; d <= daysInMonth; d++) {
            sum += toNum(hot.getDataAtCell(i, d));
          }
          return sum;
        }
      }
      return 0;
    }

    // Sum leads from leads table
    var leads = 0;
    leadKeys.forEach(function (k) {
      leads += sumFromHot(hotLeads, leadsRows, k);
    });

    // Update individual lead totals
    leadKeys.forEach(function (k) {
      var val = sumFromHot(hotLeads, leadsRows, k);
      var el = document.querySelector('.kpi-lead-total[data-key="' + k + '"]');
      if (el) el.textContent = fmtInt(val);
    });

    // Sum sales data from sales table
    var calls = sumFromHot(hotSales, salesRows, "calls");
    var apps = sumFromHot(hotSales, salesRows, "appointments");
    var quotes = sumFromHot(hotSales, salesRows, "quotes");
    var quoteVal = sumFromHot(hotSales, salesRows, "quote_value");
    var sales = sumFromHot(hotSales, salesRows, "sales");
    var salesVal = sumFromHot(hotSales, salesRows, "sales_value");

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

  // ---------- Create a Handsontable for Activity ----------
  function createActivityTable(containerId, rows, prefill, daysInMonth, isLeadsTable) {
    var container = document.getElementById(containerId);
    if (!container || !window.Handsontable) return null;

    var totalCol = daysInMonth + 1;
    var hasTotalRow = true;
    var totalRowIndex = hasTotalRow ? rows.length : -1;

    // build columns
    var colHeaders = ["Metric"];
    for (var d = 1; d <= daysInMonth; d++) colHeaders.push(String(d));
    colHeaders.push("TOTAL");

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
    if (hasTotalRow) {
      var totalRow = [isLeadsTable ? "Total Leads" : "Total Sales"];
      var grandTotal = 0;
      for (var d = 1; d <= daysInMonth; d++) {
        var daySum = 0;
        for (var r = 0; r < rows.length; r++) {
          daySum += toNum(data[r][d]);
        }
        grandTotal += daySum;
        totalRow.push(fmtInt(daySum));
      }
      totalRow.push(fmtInt(grandTotal));
      data.push(totalRow);
    }

    function firstColRenderer(instance, td, row, col, prop, value) {
      Handsontable.renderers.TextRenderer.apply(this, arguments);
      td.style.fontWeight = "800";
      td.style.whiteSpace = "nowrap";
      td.classList.add("kpi-hot-metric");
      if (isLeadsTable) {
        td.classList.add("kpi-hot-metric--leads");
      } else {
        td.classList.add("kpi-hot-metric--pipe");
      }
    }

    function totalRenderer(instance, td, row, col, prop, value) {
      Handsontable.renderers.TextRenderer.apply(this, arguments);
      td.style.fontWeight = "900";
      td.classList.add("kpi-hot-total");
    }

    function totalRowRenderer(instance, td, row, col, prop, value) {
      Handsontable.renderers.TextRenderer.apply(this, arguments);
      td.style.fontWeight = "900";
      td.classList.add("kpi-hot-total-row");
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
      rowHeaderWidth: 0,
      height: "auto",
      licenseKey: "non-commercial-and-evaluation",
      manualColumnResize: true,
      manualRowResize: true,
      stretchH: "none",
      contextMenu: true,
      outsideClickDeselects: false,
      columnSorting: false,
      minSpareRows: 0,
      rowHeaders: false,

      cells: function (row, col) {
        var cp = {};
        var isLabelCol = col === 0;
        var isTotalCol = col === totalCol;

        if (hasTotalRow && row === totalRowIndex) {
          cp.readOnly = true;
          cp.renderer = totalRowRenderer;
          return cp;
        }
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
        if (!changes || source === "loadData" || source === "calc") return;

        var touched = new Set();
        changes.forEach(function (c) {
          var row = c[0];
          var col = c[1];
          if (col >= 1 && col <= daysInMonth) touched.add(row);
        });

        hot.batch(function () {
          touched.forEach(function (r) {
            hot.setDataAtCell(r, totalCol, recalcRowTotal(hot, r), "calc");
          });
          if (hasTotalRow) {
            var grand = 0;
            for (var d = 1; d <= daysInMonth; d++) {
              var daySum = 0;
              for (var r = 0; r < rows.length; r++) {
                daySum += toNum(hot.getDataAtCell(r, d));
              }
              grand += daySum;
              hot.setDataAtCell(totalRowIndex, d, fmtInt(daySum), "calc");
            }
            hot.setDataAtCell(totalRowIndex, totalCol, fmtInt(grand), "calc");
          }
        });

        recalcAllCards();
      },
    });

    // initial totals
    hot.batch(function () {
      for (var i = 0; i < rows.length; i++) {
        hot.setDataAtCell(i, totalCol, recalcRowTotal(hot, i), "calc");
      }
      if (hasTotalRow) {
        var grand = 0;
        for (var d = 1; d <= daysInMonth; d++) {
          var daySum = 0;
          for (var r = 0; r < rows.length; r++) {
            daySum += toNum(hot.getDataAtCell(r, d));
          }
          grand += daySum;
          hot.setDataAtCell(totalRowIndex, d, fmtInt(daySum), "calc");
        }
        hot.setDataAtCell(totalRowIndex, totalCol, fmtInt(grand), "calc");
      }
    });

    return hot;
  }

  // ---------- Handsontable: Activity (Two Tables) ----------
  function initHotActivity() {
    leadsRows = readJson("kpi_leads_rows") || [];
    var leadsPrefill = readJson("kpi_leads_prefill") || [];
    salesRows = readJson("kpi_sales_rows") || [];
    var salesPrefill = readJson("kpi_sales_prefill") || [];
    activityMeta = readJson("kpi_activity_meta") || {};
    var daysInMonth = activityMeta.daysInMonth || 30;

    // Initialize Leads table
    if (document.getElementById("kpiHotLeads")) {
      hotLeads = createActivityTable("kpiHotLeads", leadsRows, leadsPrefill, daysInMonth, true);
    }

    // Initialize Sales table
    if (document.getElementById("kpiHotSales")) {
      hotSales = createActivityTable("kpiHotSales", salesRows, salesPrefill, daysInMonth, false);
    }

    // Initial card recalc
    recalcAllCards();

    // Form submit handler
    var form = document.getElementById("kpiActivityForm");
    if (form) {
      form.addEventListener("submit", function () {
        var ym = activityMeta.ym || (kpiFront?.todayYm ?? "");
        var y = parseInt(ym.slice(0, 4), 10);
        var m = parseInt(ym.slice(5, 7), 10);

        var payload = { kpi: {} };

        for (var d = 1; d <= daysInMonth; d++) {
          var dd = String(d).padStart(2, "0");
          var mm = String(m).padStart(2, "0");
          var date = y + "-" + mm + "-" + dd;

          payload.kpi[date] = {};

          // Get data from Leads table
          if (hotLeads) {
            leadsRows.forEach(function (r, i) {
              var v = toNum(hotLeads.getDataAtCell(i, d));
              payload.kpi[date][r.key] =
                r.type === "money" ? Math.round(v * 100) / 100 : Math.round(v);
            });
          }

          // Get data from Sales table
          if (hotSales) {
            salesRows.forEach(function (r, i) {
              var v = toNum(hotSales.getDataAtCell(i, d));
              payload.kpi[date][r.key] =
                r.type === "money" ? Math.round(v * 100) / 100 : Math.round(v);
            });
          }
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
    var meta = readJson("kpi_monthly_meta") || {};
    if (!grid.length) return;

    var yearShort = meta.yearShort || String(meta.year || new Date().getFullYear()).slice(-2);

    var data = grid.map(function (r) {
      return r.slice(0, r.length - 1);
    });

    var rowTypes = grid.map(function (r) {
      var flag = r[r.length - 1] || '';
      if (flag === '__section__') return 'section';
      if (flag === '__empty__') return 'empty';
      if (flag === 'total') return 'total';
      return '';
    });

    var monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    var colHeaders = ["Metric"];
    for (var i = 0; i < 12; i++) {
      colHeaders.push(monthNames[i] + "-" + yearShort);
    }
    colHeaders.push("Year to Date");
    colHeaders.push("Averages");

    function metricRenderer(instance, td, row, col, prop, value) {
      Handsontable.renderers.TextRenderer.apply(this, arguments);
      var type = rowTypes[row];

      if (type === 'section') {
        td.style.fontWeight = "900";
        td.style.fontSize = "13px";
        td.classList.add("kpi-hot-section");
      } else if (type === 'total') {
        td.style.fontWeight = "900";
        td.classList.add("kpi-hot-metric", "kpi-hot-metric--total");
      } else if (type === 'empty') {
        td.classList.add("kpi-hot-empty");
      } else {
        td.style.fontWeight = "700";
        td.classList.add("kpi-hot-metric");
      }
    }

    function cellRenderer(instance, td, row, col, prop, value) {
      Handsontable.renderers.TextRenderer.apply(this, arguments);
      var type = rowTypes[row];

      if (type === 'section') {
        td.classList.add("kpi-hot-section");
      } else if (type === 'total') {
        td.style.fontWeight = "800";
        td.classList.add("kpi-hot-num", "kpi-hot-num--total");
      } else if (type === 'empty') {
        td.classList.add("kpi-hot-empty");
      } else {
        td.classList.add("kpi-hot-num");
      }

      if (col === 13 || col === 14) {
        td.classList.add("kpi-hot-summary");
        if (type === 'total') {
          td.style.fontWeight = "900";
        }
      }
    }

    new Handsontable(container, {
      data: data,
      colHeaders: colHeaders,
      rowHeaders: false,
      height: "60vh",
      readOnly: true,
      licenseKey: "non-commercial-and-evaluation",
      manualColumnResize: true,
      stretchH: "none",
      cells: function (row, col) {
        var cp = { readOnly: true };
        cp.renderer = col === 0 ? metricRenderer : cellRenderer;
        return cp;
      },
    });
  }

  // ---------- Settings Drawer ----------
  function initSettingsDrawer() {
    var toggleBtn = document.getElementById("kpiSettingsToggle");
    var drawer = document.getElementById("kpiSettingsDrawer");
    var closeBtn = document.getElementById("kpiSettingsClose");
    var overlay = document.getElementById("kpiSettingsOverlay");

    if (!toggleBtn || !drawer) return;

    function openDrawer() {
      drawer.classList.add("is-open");
      if (overlay) overlay.classList.add("is-visible");
    }

    function closeDrawer() {
      drawer.classList.remove("is-open");
      if (overlay) overlay.classList.remove("is-visible");
    }

    toggleBtn.addEventListener("click", openDrawer);
    if (closeBtn) closeBtn.addEventListener("click", closeDrawer);
    if (overlay) overlay.addEventListener("click", closeDrawer);

    // Close on escape key
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && drawer.classList.contains("is-open")) {
        closeDrawer();
      }
    });
  }

  // ---------- Setup form: live update "Other" checkbox labels ----------
  function initSetupOtherLabelSync() {
    var $in1 = $('input[name="labels[leads_other_1]"]');
    var $in2 = $('input[name="labels[leads_other_2]"]');

    if (!$in1.length && !$in2.length) return;

    function safeLabel(v) {
      v = (v || "").trim();
      return v ? v : "Other";
    }

    function setCheckboxText(leadKey, txt) {
      var $cb = $('input[name="channels[]"][value="' + leadKey + '"]');
      if (!$cb.length) return;
      $cb.closest(".kpi-check").find("span").text(txt);
    }

    function syncAll() {
      if ($in1.length) setCheckboxText("leads_other_1", safeLabel($in1.val()));
      if ($in2.length) setCheckboxText("leads_other_2", safeLabel($in2.val()));
    }

    syncAll();

    $in1.on("input", function () {
      setCheckboxText("leads_other_1", safeLabel(this.value));
    });

    $in2.on("input", function () {
      setCheckboxText("leads_other_2", safeLabel(this.value));
    });
  }

  // ---------- init ----------
  $(function () {
    initHotActivity();
    initHotMonthly();
    initSettingsDrawer();
    initSetupOtherLabelSync();
  });
})(jQuery);
