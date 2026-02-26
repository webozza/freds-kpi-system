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
      kpiFront?.moneyDecimals ?? 2,
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
  var autosaveQueue = [];
  var autosaveTimer = null;
  var showDeleteModal = null;

  // ---------- autosave (debounced patch) ----------
  function queueAutosaveChange(date, key, value) {
    autosaveQueue.push({ date: date, key: key, value: value });
    scheduleAutosaveFlush();
  }

  function scheduleAutosaveFlush() {
    if (autosaveTimer) clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(flushAutosave, 800);
  }

  function flushAutosave() {
    if (!autosaveQueue.length) return;

    setAutosaveStatus("saving");
    var payload = { changes: autosaveQueue.slice(0) };
    autosaveQueue = [];

    $.post(kpiFront.ajaxUrl, {
      action: "kpi_autosave_patch",
      nonce: kpiFront.nonce,
      patch: JSON.stringify(payload),
    }).done(function () {
      setAutosaveStatus("saved");
    }).fail(function (xhr) {
      setAutosaveStatus("error");
      try {
        var back = payload.changes || [];
        autosaveQueue = back.concat(autosaveQueue);
      } catch (e) { }
    });
  }

  function setAutosaveStatus(state) {
    var el = document.getElementById("kpiAutosaveStatus");
    if (!el) return;
    el.className = "kpi-autosave-status";
    if (state === "saving") {
      el.classList.add("kpi-autosave-status--saving");
      el.textContent = "Saving…";
    } else if (state === "saved") {
      el.classList.add("kpi-autosave-status--saved");
      el.textContent = "✓ Saved";
      setTimeout(function () {
        if (el.classList.contains("kpi-autosave-status--saved")) {
          el.textContent = "";
          el.className = "kpi-autosave-status";
        }
      }, 3000);
    } else if (state === "error") {
      el.classList.add("kpi-autosave-status--error");
      el.textContent = "⚠ Not saved";
    }
  }

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

    var leads = 0;
    leadKeys.forEach(function (k) {
      leads += sumFromHot(hotLeads, leadsRows, k);
    });

    leadKeys.forEach(function (k) {
      var val = sumFromHot(hotLeads, leadsRows, k);
      var el = document.querySelector('.kpi-lead-total[data-key="' + k + '"]');
      if (el) el.textContent = fmtInt(val);
    });

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
  function createActivityTable(
    containerId,
    rows,
    prefill,
    daysInMonth,
    isLeadsTable,
  ) {
    var container = document.getElementById(containerId);
    if (!container || !window.Handsontable) return null;

    var totalCol = daysInMonth + 1;
    var hasTotalRow = !!isLeadsTable;
    var totalRowIndex = hasTotalRow ? rows.length : -1;

    var colHeaders = ["Day"];
    for (var d = 1; d <= daysInMonth; d++) colHeaders.push(String(d));
    colHeaders.push("TOTAL");

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
      if (isLeadsTable) td.classList.add("kpi-hot-metric--leads");
      else td.classList.add("kpi-hot-metric--pipe");
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

    function recalcRowTotal(hot, rowIndex) {
      var r = rows[rowIndex] || {};
      var sum = 0;
      for (var d = 1; d <= daysInMonth; d++)
        sum += toNum(hot.getDataAtCell(rowIndex, d));
      return r.type === "money" ? fmtMoney(sum) : fmtInt(sum);
    }

    var hot = new Handsontable(container, {
      data: data,
      columns: colHeaders.map(function (h) {
        return { title: h, className: 'htCenter cell-bg-color' };
      }),
      rowHeaderWidth: 0,
      height: "auto",
      licenseKey: "non-commercial-and-evaluation",
      manualColumnResize: true,
      manualRowResize: true,
      stretchH: 'all',
      width:"100%",
      contextMenu: true,
      outsideClickDeselects: false,
      columnSorting: false,
      minSpareRows: 0,
      rowHeaders: false,
      undo: true,
      fillHandle: true,

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

        var rMeta = rows[row] || {};

        if (rMeta.type === "money") {
          cp.type = "numeric";
          cp.numericFormat = { pattern: "0,0.00" };
          cp.renderer = Handsontable.renderers.NumericRenderer;
          return cp;
        }

        cp.type = "numeric";
        cp.numericFormat = { pattern: "0,0" };
        cp.renderer = Handsontable.renderers.NumericRenderer;
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
              for (var r = 0; r < rows.length; r++)
                daySum += toNum(hot.getDataAtCell(r, d));
              grand += daySum;
              hot.setDataAtCell(totalRowIndex, d, fmtInt(daySum), "calc");
            }
            hot.setDataAtCell(totalRowIndex, totalCol, fmtInt(grand), "calc");
          }
        });

        recalcAllCards();

        var ym = activityMeta.ym || (kpiFront?.todayYm ?? "");
        var y = parseInt(ym.slice(0, 4), 10);
        var mm = parseInt(ym.slice(5, 7), 10);

        changes.forEach(function (c) {
          var row = c[0];
          var col = c[1];

          if (col < 1 || col > daysInMonth) return;
          if (hasTotalRow && row === totalRowIndex) return;

          var day = col;
          var date =
            y +
            "-" +
            String(mm).padStart(2, "0") +
            "-" +
            String(day).padStart(2, "0");

          var key = rows[row] && rows[row].key ? rows[row].key : null;
          if (!key) return;

          var value = hot.getDataAtCell(row, col);
          queueAutosaveChange(date, key, value);
        });
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
          for (var r = 0; r < rows.length; r++)
            daySum += toNum(hot.getDataAtCell(r, d));
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

    if (document.getElementById("kpiHotLeads")) {
      hotLeads = createActivityTable(
        "kpiHotLeads",
        leadsRows,
        leadsPrefill,
        daysInMonth,
        true,
      );
    }

    if (document.getElementById("kpiHotSales")) {
      hotSales = createActivityTable(
        "kpiHotSales",
        salesRows,
        salesPrefill,
        daysInMonth,
        false,
      );
    }

    recalcAllCards();

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

          if (hotLeads) {
            leadsRows.forEach(function (r, i) {
              var v = toNum(hotLeads.getDataAtCell(i, d));
              payload.kpi[date][r.key] = Math.round(v);
            });
          }

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

    var data = grid.map(function (r) {
      return r.slice(0, r.length - 1);
    });

    var rowTypes = grid.map(function (r) {
      var flag = r[r.length - 1] || "";
      if (flag === "__section__") return "section";
      if (flag === "__empty__") return "empty";
      if (flag === "total") return "total";
      return "";
    });

    // Issue 2: use FY/CY month labels from meta
    var colHeaders = ["Month"];
    if (meta.monthLabels && meta.monthLabels.length === 12) {
      for (var i = 0; i < 12; i++) colHeaders.push(meta.monthLabels[i]);
    } else {
      var monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
      var ys = meta.yearShort || String(meta.year || new Date().getFullYear()).slice(-2);
      for (var i = 0; i < 12; i++) colHeaders.push(monthNames[i] + "-" + ys);
    }

    colHeaders.push("Year to Date");
    colHeaders.push("Averages");

    function metricRenderer(instance, td, row) {
      Handsontable.renderers.TextRenderer.apply(this, arguments);
      var type = rowTypes[row];

      if (type === "section") {
        td.style.fontWeight = "900";
        td.style.fontSize = "13px";
        td.classList.add("kpi-hot-section");
      } else if (type === "total") {
        td.style.fontWeight = "900";
        td.classList.add("kpi-hot-metric", "kpi-hot-metric--total");
      } else if (type === "empty") {
        td.classList.add("kpi-hot-empty");
      } else {
        td.style.fontWeight = "700";
        td.classList.add("kpi-hot-metric");
      }
    }

    function cellRenderer(instance, td, row, col) {
      Handsontable.renderers.TextRenderer.apply(this, arguments);
      var type = rowTypes[row];

      if (type === "section") {
        td.classList.add("kpi-hot-section");
      } else if (type === "total") {
        td.style.fontWeight = "800";
        td.classList.add("kpi-hot-num", "kpi-hot-num--total");
      } else if (type === "empty") {
        td.classList.add("kpi-hot-empty");
      } else {
        td.classList.add("kpi-hot-num");
      }

      if (col === 13 || col === 14) {
        td.classList.add("kpi-hot-summary");
        if (type === "total") td.style.fontWeight = "900";
      }
    }

    new Handsontable(container, {
      data: data,
      columns: colHeaders.map(function (h) {
        return { title: h, className: 'htCenter cell-bg-color' };
      }),
      rowHeaders: false,
      height: "auto",
      stretchH: 'all',
      width:"100%",
      readOnly: true,
      licenseKey: "non-commercial-and-evaluation",
      manualColumnResize: true,
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

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && drawer.classList.contains("is-open"))
        closeDrawer();
    });
  }

  // ---------- Channel Editor (Setup + Drawer) ----------
  function initOneChannelEditor(editorId, addBtnId, jsonInputId, opts) {
    opts = opts || {};
    var editor = document.getElementById(editorId);
    var addBtn = document.getElementById(addBtnId);
    var jsonInput = document.getElementById(jsonInputId);

    if (!editor || !addBtn || !jsonInput) return;

    function collectRows() {
      var rows = editor.querySelectorAll(".kpi-channel-row");
      var out = [];
      var order = 0;

      rows.forEach(function (row) {
        var idAttr = row.getAttribute("data-id");
        var id = idAttr ? parseInt(idAttr, 10) : 0;

        var nameEl = row.querySelector(".kpi-channel-name");
        var activeEl = row.querySelector(".kpi-channel-active");

        var name = (nameEl ? nameEl.value : "").trim();
        if (!name) return;

        out.push({
          id: id,
          name: name,
          is_active: activeEl && activeEl.checked ? 1 : 0,
          sort_order: order++,
        });
      });

      return out;
    }

    function syncJson() {
      jsonInput.value = JSON.stringify(collectRows());
    }

    editor.addEventListener("input", function (e) {
      if (e.target.classList.contains("kpi-channel-name")) syncJson();
    });

    editor.addEventListener("change", function (e) {
      if (e.target.classList.contains("kpi-channel-active")) syncJson();
    });

    editor.addEventListener("click", function (e) {
      if (e.target.classList.contains("kpi-channel-remove")) {
        e.preventDefault();
        var row = e.target.closest(".kpi-channel-row");
        if (!row) return;
        var rowId = parseInt(row.getAttribute("data-id") || "0", 10);
        if (opts.confirmDelete && rowId > 0 && showDeleteModal) {
          var nameEl = row.querySelector(".kpi-channel-name");
          var channelName = nameEl ? nameEl.value.trim() : "this channel";
          showDeleteModal(channelName, function () {
            row.remove();
            syncJson();
          });
        } else {
          row.remove();
          syncJson();
        }
      }
    });

    addBtn.addEventListener("click", function () {
      var row = document.createElement("div");
      row.className = "kpi-channel-row";
      row.setAttribute("data-id", "0");
      row.innerHTML =
        '<input type="checkbox" class="kpi-channel-active" checked>' +
        '<input type="text" class="kpi-channel-name" placeholder="Channel name" value="">' +
        '<button type="button" class="kpi-channel-remove">Remove</button>';

      editor.appendChild(row);

      var inp = row.querySelector(".kpi-channel-name");
      if (inp) inp.focus();

      syncJson();
    });

    var form = editor.closest("form");
    if (form) {
      form.addEventListener("submit", function () {
        syncJson();
      });
    }

    syncJson();
  }

  function initChannelEditors() {
    // Setup form (initial onboarding)
    initOneChannelEditor(
      "kpiChannelEditor",
      "kpiAddChannel",
      "kpi_channels_json",
    );

    // Drawer: global editor (confirm before deleting existing channels)
    initOneChannelEditor(
      "kpiChannelEditor_global",
      "kpiAddChannel_global",
      "kpi_channels_json_global",
      { confirmDelete: true },
    );

    // Drawer: period-specific editor
    initOneChannelEditor(
      "kpiChannelEditor_period",
      "kpiAddChannel_period",
      "kpi_channels_json_period",
    );
  }

  function initYearCycleToggle() {
    var wrap = document.getElementById("kpiFyStartWrap");
    if (!wrap) return;

    function refresh() {
      var fin = document.querySelector(
        'input[name="kpi_year_mode"][value="financial"]',
      );
      wrap.style.display = fin && fin.checked ? "" : "none";
    }

    document.addEventListener("change", function (e) {
      if (e.target && e.target.name === "kpi_year_mode") refresh();
    });

    refresh();
  }

  // ---------- Settings Drawer Tabs ----------
  function initDrawerTabs() {
    var tabs = document.querySelectorAll(".kpi-dtab");
    if (!tabs.length) return;

    var settingsForm = document.getElementById("kpiSettingsForm");
    var periodInput = document.getElementById("kpiDrawerPeriodInput");
    var mainJsonInput = document.getElementById("kpi_channels_json_settings");
    var currentView = "global";

    function syncFormForActiveTab() {
      var activeJsonId = currentView === "global"
        ? "kpi_channels_json_global"
        : "kpi_channels_json_period";
      var activeJson = document.getElementById(activeJsonId);
      if (activeJson && mainJsonInput) mainJsonInput.value = activeJson.value;
      if (periodInput) {
        // "" → saved as null (global), "YYYY-MM" → saved as period
        periodInput.value = currentView === "global"
          ? ""
          : (periodInput.getAttribute("data-ym") || "");
      }
    }

    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        var view = tab.getAttribute("data-view");
        if (!view) return;
        currentView = view;

        tabs.forEach(function (t) { t.classList.remove("is-active"); });
        tab.classList.add("is-active");

        var globalPanel = document.getElementById("kpiDrawerPanel_global");
        var periodPanel = document.getElementById("kpiDrawerPanel_period");
        if (globalPanel) globalPanel.style.display = view === "global" ? "" : "none";
        if (periodPanel) periodPanel.style.display = view === "period" ? "" : "none";

        syncFormForActiveTab();
      });
    });

    if (settingsForm) {
      settingsForm.addEventListener("submit", function () {
        syncFormForActiveTab();
      });
    }

    syncFormForActiveTab();
  }

  // ---------- Delete Confirmation Modal ----------
  function initDeleteModal() {
    var modal = document.createElement("div");
    modal.className = "kpi-modal-backdrop";
    modal.id = "kpiDeleteModal";
    modal.innerHTML =
      '<div class="kpi-modal-box">' +
      '<h3 class="kpi-modal-title">Delete channel permanently?</h3>' +
      '<p class="kpi-modal-body" id="kpiDeleteModalBody"></p>' +
      '<div class="kpi-modal-actions">' +
      '<button type="button" class="kpi-btn kpi-btn--danger" id="kpiDeleteConfirmBtn">Delete permanently</button>' +
      '<button type="button" class="kpi-btn kpi-btn--ghost" id="kpiDeleteCancelBtn">Cancel</button>' +
      '</div>' +
      '</div>';
    document.body.appendChild(modal);

    function hideModal() {
      modal.classList.remove("is-visible");
    }

    modal.addEventListener("click", function (e) {
      if (e.target === modal) hideModal();
    });

    document.getElementById("kpiDeleteCancelBtn").addEventListener("click", hideModal);

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && modal.classList.contains("is-visible")) hideModal();
    });

    showDeleteModal = function (channelName, onConfirm) {
      var bodyEl = document.getElementById("kpiDeleteModalBody");
      if (bodyEl) {
        bodyEl.innerHTML =
          "Deleting <strong>" + escHtml(channelName) + "</strong> will permanently remove " +
          "all historical lead data for this channel across every month. This cannot be undone.";
      }

      // Replace confirm button to clear any previous listener
      var oldBtn = document.getElementById("kpiDeleteConfirmBtn");
      var newBtn = oldBtn.cloneNode(true);
      oldBtn.parentNode.replaceChild(newBtn, oldBtn);
      newBtn.addEventListener("click", function () {
        hideModal();
        onConfirm();
      });

      modal.classList.add("is-visible");
    };
  }

  // ---------- Issue 1: Per-period channel prompt ----------
  function initPeriodChannelPrompt() {
    var info = readJson("kpi_period_info");
    if (!info || info.has_own_channels) return;

    var shell = document.querySelector(".kpi-shell");
    var activityForm = document.getElementById("kpiActivityForm");
    if (!shell || !activityForm) return;

    var nearestHtml = info.nearest_period
      ? '<button type="button" class="kpi-banner-btn kpi-banner-btn--primary" id="kpiCopyChannels">' +
      'Copy from ' + escHtml(info.nearest_period_label) +
      '</button>'
      : '';

    var banner = document.createElement("div");
    banner.className = "kpi-period-banner";
    banner.innerHTML =
      '<div class="kpi-period-banner-inner">' +
      '<div class="kpi-period-banner-text">' +
      '<strong>' + escHtml(info.period_label) + '</strong> is using your global channel settings.' +
      (info.nearest_period
        ? ' Copy from <strong>' + escHtml(info.nearest_period_label) + '</strong> to customise this month independently.'
        : ' Open settings to configure channels for this month.') +
      '</div>' +
      '<div class="kpi-period-banner-actions">' +
      nearestHtml +
      '<button type="button" class="kpi-banner-btn kpi-banner-btn--ghost" id="kpiOpenSettingsFromBanner">Customise this month</button>' +
      '<button type="button" class="kpi-banner-btn kpi-banner-btn--dismiss" id="kpiDismissBanner" title="Dismiss">&times;</button>' +
      '</div>' +
      '</div>';

    shell.insertBefore(banner, activityForm);

    // Copy from previous period
    var copyBtn = document.getElementById("kpiCopyChannels");
    if (copyBtn) {
      copyBtn.addEventListener("click", function () {
        copyBtn.disabled = true;
        copyBtn.textContent = "Copying…";

        $.post(kpiFront.ajaxUrl, {
          action: "kpi_copy_period_channels",
          nonce: kpiFront.nonceCopy,
          from_period: info.nearest_period,
          to_period: info.period,
        })
          .done(function (res) {
            if (res.success) {
              window.location.reload();
            } else {
              copyBtn.disabled = false;
              copyBtn.textContent = "Copy from " + info.nearest_period_label;
              alert("Could not copy channels. Please try again.");
            }
          })
          .fail(function () {
            copyBtn.disabled = false;
            copyBtn.textContent = "Copy from " + info.nearest_period_label;
            alert("Request failed. Please try again.");
          });
      });
    }

    // Open settings drawer
    var openBtn = document.getElementById("kpiOpenSettingsFromBanner");
    if (openBtn) {
      openBtn.addEventListener("click", function () {
        var settingsToggle = document.getElementById("kpiSettingsToggle");
        if (settingsToggle) settingsToggle.click();
      });
    }

    // Dismiss
    var dismissBtn = document.getElementById("kpiDismissBanner");
    if (dismissBtn) {
      dismissBtn.addEventListener("click", function () {
        banner.style.opacity = "0";
        banner.style.transform = "translateY(-8px)";
        setTimeout(function () { banner.remove(); }, 300);
      });
    }
  }

  // HTML-escape helper for JS
  function escHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  // ---------- Issue 3: Charts (Chart.js) ----------
  function initCharts() {
    var chartData = readJson("kpi_chart_data");
    if (!chartData || !window.Chart) return;

    var chartDefaults = {
      color: "rgba(255,255,255,0.85)",
      plugins: {
        legend: {
          labels: {
            color: "rgba(255,255,255,0.85)",
            font: { size: 12, weight: "700" },
            padding: 16,
            usePointStyle: true,
          },
        },
        tooltip: {
          backgroundColor: "rgba(11,18,32,0.95)",
          borderColor: "rgba(255,255,255,0.14)",
          borderWidth: 1,
          titleColor: "rgba(255,255,255,0.9)",
          bodyColor: "rgba(255,255,255,0.75)",
          padding: 12,
          cornerRadius: 10,
        },
      },
    };

    // Shared grid styling
    var gridStyle = {
      color: "rgba(255,255,255,0.08)",
      borderColor: "rgba(255,255,255,0.12)",
    };

    var tickStyle = { color: "rgba(255,255,255,0.6)", font: { size: 11 } };

    // ── Chart 1: Leads by Channel (Donut) ──────────────────────────────
    var leadsCtx = document.getElementById("kpiChartLeads");
    if (leadsCtx && chartData.leadsByChannel) {
      var ld = chartData.leadsByChannel;
      var palette = [
        "#66f0c2", "#8aa6ff", "#ffb347", "#ff6b6b", "#c9a0dc",
        "#87d37c", "#f7ca18", "#ff8c94", "#6bc5f8", "#f0a500",
        "#a29bfe", "#fd79a8", "#55efc4", "#fdcb6e", "#e17055",
      ];

      new Chart(leadsCtx, {
        type: "doughnut",
        data: {
          labels: ld.labels,
          datasets: [{
            data: ld.values,
            backgroundColor: ld.labels.map(function (_, i) {
              return palette[i % palette.length];
            }),
            borderColor: "rgba(11,18,32,0.6)",
            borderWidth: 2,
            hoverOffset: 6,
          }],
        },
        options: Object.assign({}, chartDefaults, {
          cutout: "62%",
          plugins: Object.assign({}, chartDefaults.plugins, {
            legend: Object.assign({}, chartDefaults.plugins.legend, {
              position: "right",
              labels: Object.assign({}, chartDefaults.plugins.legend.labels, {
                boxWidth: 14,
                boxHeight: 14,
              }),
            }),
          }),
        }),
      });
    }

    // ── Chart 2: Monthly Pipeline (Multi-line) ──────────────────────────
    var pipeCtx = document.getElementById("kpiChartPipeline");
    if (pipeCtx && chartData.monthlyPipeline) {
      var pd = chartData.monthlyPipeline;
      new Chart(pipeCtx, {
        type: "line",
        data: {
          labels: pd.months,
          datasets: [
            {
              label: "Calls",
              data: pd.calls,
              borderColor: "#66f0c2",
              backgroundColor: "rgba(102,240,194,0.12)",
              pointBackgroundColor: "#66f0c2",
              tension: 0.35,
              fill: false,
              borderWidth: 2,
              pointRadius: 4,
            },
            {
              label: "Appointments",
              data: pd.appointments,
              borderColor: "#8aa6ff",
              backgroundColor: "rgba(138,166,255,0.12)",
              pointBackgroundColor: "#8aa6ff",
              tension: 0.35,
              fill: false,
              borderWidth: 2,
              pointRadius: 4,
            },
            {
              label: "Quotes",
              data: pd.quotes,
              borderColor: "#ffb347",
              backgroundColor: "rgba(255,179,71,0.12)",
              pointBackgroundColor: "#ffb347",
              tension: 0.35,
              fill: false,
              borderWidth: 2,
              pointRadius: 4,
            },
            {
              label: "Sales",
              data: pd.sales,
              borderColor: "#ff6b6b",
              backgroundColor: "rgba(255,107,107,0.12)",
              pointBackgroundColor: "#ff6b6b",
              tension: 0.35,
              fill: false,
              borderWidth: 2.5,
              pointRadius: 5,
            },
          ],
        },
        options: Object.assign({}, chartDefaults, {
          scales: {
            x: {
              grid: gridStyle,
              ticks: tickStyle,
            },
            y: {
              beginAtZero: true,
              grid: gridStyle,
              ticks: Object.assign({}, tickStyle, { precision: 0 }),
            },
          },
        }),
      });
    }

    // ── Chart 3: Monthly Revenue (Grouped Bar) ──────────────────────────
    var revCtx = document.getElementById("kpiChartRevenue");
    if (revCtx && chartData.monthlyRevenue) {
      var rd = chartData.monthlyRevenue;
      var sym = (kpiFront && kpiFront.currencySymbol) ? kpiFront.currencySymbol : "$";

      new Chart(revCtx, {
        type: "bar",
        data: {
          labels: rd.months,
          datasets: [
            {
              label: "Quote Value",
              data: rd.quoteValue,
              backgroundColor: "rgba(138,166,255,0.65)",
              borderColor: "#8aa6ff",
              borderWidth: 1,
              borderRadius: 6,
            },
            {
              label: "Sales Value",
              data: rd.salesValue,
              backgroundColor: "rgba(102,240,194,0.65)",
              borderColor: "#66f0c2",
              borderWidth: 1,
              borderRadius: 6,
            },
          ],
        },
        options: Object.assign({}, chartDefaults, {
          scales: {
            x: {
              grid: gridStyle,
              ticks: tickStyle,
            },
            y: {
              beginAtZero: true,
              grid: gridStyle,
              ticks: Object.assign({}, tickStyle, {
                callback: function (val) {
                  if (val >= 1000000) return sym + (val / 1000000).toFixed(1) + "M";
                  if (val >= 1000) return sym + (val / 1000).toFixed(0) + "k";
                  return sym + val;
                },
              }),
            },
          },
        }),
      });
    }
  }

  // ---------- init ----------
  $(function () {
    initHotActivity();
    initHotMonthly();
    initSettingsDrawer();
    initDeleteModal();
    initChannelEditors();
    initDrawerTabs();
    initYearCycleToggle();
    initPeriodChannelPrompt();
    initCharts();
  });
})(jQuery);
