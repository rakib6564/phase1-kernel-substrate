/*
 * Content Builder — block editor (vanilla JS, no build step).
 *
 * Reads the palette from window.CB_PALETTE (printed by post-edit.php),
 * keeps an in-memory layout array, and serializes it into #cb-layout on
 * every change so a normal form POST carries the JSON.
 *
 * Supports: add, reorder (up/down), edit fields, delete, and one level of
 * nesting for the "columns" block (each column holds its own blocks).
 */
(function () {
  "use strict";

  var PALETTE = window.CB_PALETTE || {};
  var layoutInput = document.getElementById("cb-layout");
  var canvas = document.getElementById("cb-canvas");
  var palette = document.getElementById("cb-palette");
  if (!layoutInput || !canvas || !palette) return;

  var layout;
  try { layout = JSON.parse(layoutInput.value || "[]"); }
  catch (e) { layout = []; }
  if (!Array.isArray(layout)) layout = [];

  // Which block cards are expanded (accordion). Keyed by the block OBJECT so
  // it survives repaints (add/move/remove keep the same references). Default:
  // all collapsed → a compact, scannable list instead of one giant scroll.
  var expanded = new WeakSet();

  function sync() {
    layoutInput.value = JSON.stringify(layout);
    paint();
  }

  function defaults(type) {
    var d = (PALETTE[type] && PALETTE[type].defaults) || {};
    return JSON.parse(JSON.stringify(d));
  }

  // Backfill any props a block is MISSING with its type's defaults, so every
  // control reflects the value the page actually renders (a block saved before
  // a field existed would otherwise show the dropdown's first option while the
  // page uses the template default — the "control shows X but renders Y" bug).
  // Only fills undefined keys, so intentional values (incl. "" / "center") win.
  function fillDefaults(block) {
    if (!block || !block.type) return;
    var d = defaults(block.type);
    block.props = block.props || {};
    Object.keys(d).forEach(function (k) {
      if (block.props[k] === undefined) block.props[k] = d[k];
    });
    // Recurse into columns (each column holds its own block list).
    if (Array.isArray(block.props.cols)) {
      block.props.cols.forEach(function (col) {
        if (col && Array.isArray(col.blocks)) col.blocks.forEach(fillDefaults);
      });
    }
  }
  function normalizeLayout() { layout.forEach(fillDefaults); }

  function addBlock(type, list) {
    var b = { type: type, props: defaults(type) };
    expanded.add(b); // newly added blocks open so you can edit right away
    list.push(b);
    sync();
  }

  // A short human summary of a block for its collapsed header (heading text,
  // else first meaningful text prop, else nothing).
  function blockSummary(block) {
    var p = block.props || {};
    var s = p.heading || p.title || p.text || p.lede || p.sub || p.eyebrow || p.label || "";
    if (typeof s !== "string") s = "";
    s = s.replace(/\s+/g, " ").trim();
    return s.length > 60 ? s.slice(0, 60) + "…" : s;
  }

  function move(list, from, to) {
    if (to < 0 || to >= list.length) return;
    var b = list.splice(from, 1)[0];
    list.splice(to, 0, b);
    sync();
  }

  function remove(list, i) { list.splice(i, 1); sync(); }

  // ---- Palette ----
  // Per-block-type icon (inline SVG paths) + short description for the palette.
  var BLOCK_ICONS = {
    heading:   '<path d="M6 4v16M18 4v16M6 12h12"/>',
    paragraph: '<path d="M4 6h16M4 12h16M4 18h10"/>',
    image:     '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>',
    button:    '<rect x="3" y="8" width="18" height="8" rx="4"/>',
    columns:   '<rect x="3" y="4" width="7" height="16"/><rect x="14" y="4" width="7" height="16"/>',
    html:      '<path d="M8 9l-3 3 3 3M16 9l3 3-3 3M13 6l-2 12"/>',
    "post-list": '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    hero:      '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 14h18M8 9h5"/>',
    "icon-grid": '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    "image-grid": '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    cta:       '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M9 12h6"/>',
    testimonial: '<path d="M7 8h6a2 2 0 012 2v3a2 2 0 01-2 2H9l-3 3v-3a2 2 0 01-2-2v-3"/>',
  };

  function buildPalette() {
    var groups = {};
    Object.keys(PALETTE).forEach(function (type) {
      var g = PALETTE[type].group || "Content";
      (groups[g] = groups[g] || []).push(type);
    });
    palette.innerHTML = "";
    Object.keys(groups).forEach(function (g) {
      var wrap = document.createElement("div");
      wrap.className = "cb-palette-group";
      wrap.innerHTML = '<span class="cb-palette-label">' + esc(g) + "</span>";
      var tiles = document.createElement("div");
      tiles.className = "cb-palette-tiles";
      groups[g].forEach(function (type) {
        var icon = BLOCK_ICONS[type] || BLOCK_ICONS.paragraph;
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "cb-tile";
        btn.title = "Add " + (PALETTE[type].label || type);
        btn.innerHTML =
          '<span class="cb-tile-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
          'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">' +
          icon + '</svg></span>' +
          '<span class="cb-tile-label">' + esc(PALETTE[type].label || type) + '</span>';
        btn.addEventListener("click", function () { addBlock(type, layout); });
        tiles.appendChild(btn);
      });
      wrap.appendChild(tiles);
      palette.appendChild(wrap);
    });
  }

  // ---- Canvas ----
  function paint() {
    canvas.innerHTML = "";
    if (!layout.length) {
      canvas.innerHTML = '<div class="cb-canvas-empty">No blocks yet. Add one above.</div>';
      return;
    }
    layout.forEach(function (block, i) {
      canvas.appendChild(renderCard(block, layout, i));
    });
  }

  // Which field keys belong on the "Style" tab (appearance/layout). Everything
  // else is "Content". A field may override with an explicit f.group.
  var STYLE_KEYS = {
    align:1, bg:1, cols:1, tall:1, mediaSide:1, btnStyle:1, btn2Style:1, shape:1,
    minHeight:1, radius:1, pad:1, padX:1, padY:1, gap:1, colGap:1, fieldGap:1,
    size:1, weight:1, variant:1, layout:1, height:1, width:1, maxWidth:1, bgColor:1,
    textColor:1, accent:1
  };
  function groupFields(fields) {
    var g = { content: [], style: [] };
    (fields || []).forEach(function (f) {
      var grp = f.group === "style" ? "style" : (f.group === "content" ? "content" : (STYLE_KEYS[f.key] ? "style" : "content"));
      g[grp].push(f);
    });
    return g;
  }
  function renderTabs(block, groups) {
    var wrap = document.createElement("div");
    wrap.className = "cb-tabs";
    var bar = document.createElement("div"); bar.className = "cb-tabbar";
    var panels = document.createElement("div"); panels.className = "cb-tabpanels";
    var order = [["content", "Content"], ["style", "Style"]];
    var firstSet = false;
    order.forEach(function (t) {
      if (!groups[t[0]].length) return;
      var active = !firstSet; firstSet = true;
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "cb-tab" + (active ? " is-active" : "");
      btn.textContent = t[1];
      var panel = document.createElement("div");
      panel.className = "cb-tabpanel" + (active ? " is-active" : "");
      groups[t[0]].forEach(function (f) { panel.appendChild(renderField(block, f)); });
      btn.addEventListener("click", function () {
        bar.querySelectorAll(".cb-tab").forEach(function (b) { b.classList.remove("is-active"); });
        panels.querySelectorAll(".cb-tabpanel").forEach(function (p) { p.classList.remove("is-active"); });
        btn.classList.add("is-active"); panel.classList.add("is-active");
      });
      bar.appendChild(btn); panels.appendChild(panel);
    });
    wrap.appendChild(bar); wrap.appendChild(panels);
    return wrap;
  }

  function renderCard(block, list, i) {
    var def = PALETTE[block.type] || { label: block.type, fields: [] };
    var isOpen = expanded.has(block);
    var card = document.createElement("div");
    card.className = "cb-block" + (isOpen ? " is-open" : "");

    var head = document.createElement("div");
    head.className = "cb-block-head";
    var summary = blockSummary(block);
    head.innerHTML =
      '<span class="cb-block-chev" aria-hidden="true">▸</span>' +
      '<span class="cb-block-titles">' +
        '<span class="cb-block-type">' + esc(def.label || block.type) + "</span>" +
        (summary ? '<span class="cb-block-sum">' + esc(summary) + "</span>" : "") +
      "</span>";

    var controls = document.createElement("div");
    controls.className = "cb-block-controls";
    controls.appendChild(iconBtn("↑", function () { move(list, i, i - 1); }));
    controls.appendChild(iconBtn("↓", function () { move(list, i, i + 1); }));
    controls.appendChild(iconBtn("✕", function () { remove(list, i); }, "cb-danger"));
    head.appendChild(controls);

    // Click the header (but not a control button) to expand/collapse in place.
    head.addEventListener("click", function (ev) {
      if (ev.target.closest(".cb-block-controls")) return;
      if (expanded.has(block)) expanded.delete(block); else expanded.add(block);
      card.classList.toggle("is-open", expanded.has(block));
    });
    card.appendChild(head);

    var body = document.createElement("div");
    body.className = "cb-block-body";

    if (def.nest) {
      body.appendChild(renderColumns(block));
    } else {
      var groups = groupFields(def.fields || []);
      if (groups.style.length) {
        body.appendChild(renderTabs(block, groups)); // tabs only when there's style to split out
      } else {
        groups.content.forEach(function (f) { body.appendChild(renderField(block, f)); });
      }
    }
    card.appendChild(body);
    return card;
  }

  function renderColumns(block) {
    if (!block.props) block.props = { cols: [{ blocks: [] }, { blocks: [] }] };
    if (!Array.isArray(block.props.cols)) block.props.cols = [{ blocks: [] }, { blocks: [] }];
    var wrap = document.createElement("div");
    wrap.className = "cb-cols-editor";
    block.props.cols.forEach(function (col, ci) {
      if (!Array.isArray(col.blocks)) col.blocks = [];
      var colEl = document.createElement("div");
      colEl.className = "cb-col-editor";
      colEl.innerHTML = '<div class="cb-col-label">Column ' + (ci + 1) + "</div>";
      col.blocks.forEach(function (b, bi) {
        colEl.appendChild(renderCard(b, col.blocks, bi));
      });
      var add = document.createElement("select");
      add.className = "cb-col-add";
      add.innerHTML = '<option value="">+ add block…</option>' +
        Object.keys(PALETTE)
          .filter(function (t) { return !PALETTE[t].nest; }) // no columns-in-columns
          .map(function (t) { return '<option value="' + t + '">' + esc(PALETTE[t].label) + "</option>"; })
          .join("");
      add.addEventListener("change", function () {
        if (add.value) { addBlock(add.value, col.blocks); }
      });
      colEl.appendChild(add);
      wrap.appendChild(colEl);
    });
    return wrap;
  }

  function renderField(block, f) {
    if (!block.props) block.props = {};

    // Repeater: an editable list of sub-item objects (icon-grid, image-grid).
    if (f.type === "repeater") {
      return renderRepeater(block, f);
    }

    var field = document.createElement("div");
    field.className = "cb-field";
    var id = "f_" + Math.random().toString(36).slice(2);
    field.innerHTML = '<label class="cb-field-label" for="' + id + '">' + esc(f.label || f.key) + "</label>";

    var input;
    if (f.type === "textarea") {
      input = document.createElement("textarea");
      input.rows = 3;
    } else if (f.type === "select") {
      input = document.createElement("select");
      (f.options || []).forEach(function (o) {
        var opt = document.createElement("option");
        opt.value = o.v; opt.textContent = o.l;
        input.appendChild(opt);
      });
    } else {
      input = document.createElement("input");
      input.type = "text";
    }
    input.id = id;
    input.className = "cb-field-input";
    if (f.placeholder) input.placeholder = f.placeholder;
    input.value = block.props[f.key] != null ? block.props[f.key] : "";
    var onEdit = function () {
      block.props[f.key] = input.value;
      layoutInput.value = JSON.stringify(layout); // live sync, no repaint (keeps focus)
    };
    // Listen to BOTH: text/number/textarea fire "input"; some browsers only
    // fire "change" for <select> — without it, a dropdown change (e.g. text
    // alignment) would silently never be saved.
    input.addEventListener("input", onEdit);
    input.addEventListener("change", onEdit);
    field.appendChild(input);

    // Image fields get a "Choose from library" button + thumbnail preview,
    // but only if the Media Library plugin exposed its MediaPicker global.
    if (f.type === "image") {
      var hasPicker = (window.CB_HAS_MEDIA === true);
      var preview = document.createElement("div");
      preview.className = "cb-img-preview";
      var refreshPreview = function () {
        var v = input.value || "";
        preview.innerHTML = v ? '<img src="' + esc(v) + '" alt="">' : "";
      };
      refreshPreview();
      input.addEventListener("input", refreshPreview);

      if (hasPicker) {
        var pick = document.createElement("button");
        pick.type = "button";
        pick.className = "cb-icon-btn";
        pick.style.width = "auto";
        pick.style.padding = "0 .6rem";
        pick.textContent = "Choose from library";
        pick.addEventListener("click", function () {
          if (!window.MediaPicker || typeof window.MediaPicker.open !== "function") {
            alert("Media library is still loading — try again in a moment.");
            return;
          }
          window.MediaPicker.open({
            mode: "single",
            onPick: function (path) {
              input.value = path;
              block.props[f.key] = path;
              layoutInput.value = JSON.stringify(layout);
              refreshPreview();
            }
          });
        });
        var row = document.createElement("div");
        row.className = "cb-img-actions";
        row.appendChild(pick);
        field.appendChild(row);
      }
      field.appendChild(preview);
    }
    return field;
  }

  function renderRepeater(block, f) {
    if (!Array.isArray(block.props[f.key])) block.props[f.key] = [];
    var list = block.props[f.key];
    var wrap = document.createElement("div");
    wrap.className = "cb-field";
    wrap.innerHTML = '<label class="cb-field-label">' + esc(f.label || f.key) + "</label>";

    var container = document.createElement("div");
    container.className = "cb-repeater";

    function repaintRepeater() {
      container.innerHTML = "";
      list.forEach(function (item, idx) {
        var card = document.createElement("div");
        card.className = "cb-repeater-item";
        var head = document.createElement("div");
        head.className = "cb-repeater-head";
        head.innerHTML = '<span>#' + (idx + 1) + "</span>";
        var ctr = document.createElement("div");
        ctr.appendChild(iconBtn("↑", function () { if (idx>0){ list.splice(idx-1,0,list.splice(idx,1)[0]); sync(); } }));
        ctr.appendChild(iconBtn("↓", function () { if (idx<list.length-1){ list.splice(idx+1,0,list.splice(idx,1)[0]); sync(); } }));
        ctr.appendChild(iconBtn("✕", function () { list.splice(idx,1); sync(); }, "cb-danger"));
        head.appendChild(ctr);
        card.appendChild(head);
        (f.item || []).forEach(function (sub) {
          card.appendChild(renderField({ props: item }, sub));
        });
        container.appendChild(card);
      });
    }

    repaintRepeater();
    wrap.appendChild(container);

    var add = document.createElement("button");
    add.type = "button";
    add.className = "cb-icon-btn";
    add.style.width = "auto";
    add.style.padding = "0 .6rem";
    add.style.marginTop = ".4rem";
    add.textContent = "+ Add item";
    add.addEventListener("click", function () {
      var blank = {};
      (f.item || []).forEach(function (sub) { blank[sub.key] = ""; });
      list.push(blank);
      sync();
    });
    wrap.appendChild(add);
    return wrap;
  }

  function iconBtn(label, fn, cls) {
    var b = document.createElement("button");
    b.type = "button";
    b.className = "cb-icon-btn" + (cls ? " " + cls : "");
    b.textContent = label;
    b.addEventListener("click", fn);
    return b;
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  // Auto-fill slug from title if slug is empty.
  var title = document.getElementById("title");
  var slug = document.getElementById("slug");
  if (title && slug) {
    title.addEventListener("blur", function () {
      if (!slug.value.trim() && title.value.trim()) {
        slug.value = title.value.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
      }
    });
  }

  // ---- Section pattern gallery ----------------------------------------
  // Premade, pre-filled sections from window.CB_PATTERNS. Clicking a card
  // appends its blocks to the layout; the user then edits them in place.
  var PATTERNS = window.CB_PATTERNS || {};

  // A pattern's `thumb` token list -> a tiny schematic preview.
  var THUMB = {
    "band":      '<span class="cb-th-band"></span>',
    "band-dark": '<span class="cb-th-band cb-th-dark"></span>',
    "img":       '<span class="cb-th-img"></span>',
    "split":     '<span class="cb-th-split"><i></i><b></b></span>',
    "h":         '<span class="cb-th-h"></span>',
    "t":         '<span class="cb-th-t"></span>',
    "btn":       '<span class="cb-th-btn"></span>',
    "row2":      '<span class="cb-th-row"><i></i><i></i></span>',
    "row3":      '<span class="cb-th-row"><i></i><i></i><i></i></span>',
    "row4":      '<span class="cb-th-row"><i></i><i></i><i></i><i></i></span>',
    "quote":     '<span class="cb-th-quote"></span>'
  };
  function thumbHtml(tokens) {
    return (tokens || []).map(function (t) { return THUMB[t] || ""; }).join("");
  }

  // The patterns carry no thumbnail data, so derive a small schematic from
  // the block types they contain (hero->banner/split, icon-grid->card row…).
  function thumbFromBlocks(blocks) {
    var out = [];
    (blocks || []).forEach(function (b) {
      var p = b.props || {};
      switch (b.type) {
        case "hero":
          if (p.layout === "split") { out.push("split"); }
          else { out.push((p.overlay === "dark") ? "band-dark" : "img", "h", "t", "btn"); }
          break;
        case "icon-grid":
          out.push((p.bg === "dark") ? "band-dark" : "h", "row" + (p.cols || "3"));
          break;
        case "image-grid": out.push("h", "row" + (p.cols || "3")); break;
        case "testimonial": out.push("quote"); break;
        case "cta": out.push("band-dark", "h", "btn"); break;
        case "heading": out.push("h"); break;
        case "paragraph": out.push("t"); break;
        case "columns": out.push("row2"); break;
        case "html":
          // A full custom-HTML design — show a finished-page schematic.
          out.push("band-dark", "h", "t", "btn", "row3", "quote", "band-dark");
          break;
        default: out.push("band");
      }
    });
    return out.length ? out : ["band"];
  }

  // Generic gallery wiring used for BOTH the section library (append) and the
  // page-template library (replace). cfg = {btnId, modalId, tabsId, gridId, onPick}.
  function setupGallery(cfg) {
    var openBtn = document.getElementById(cfg.btnId);
    var modal   = document.getElementById(cfg.modalId);
    var tabsEl  = document.getElementById(cfg.tabsId);
    var gridEl  = document.getElementById(cfg.gridId);
    if (!openBtn || !modal || !tabsEl || !gridEl) return;

    var data = cfg.data || {};
    var groups = [], byGroup = {};
    Object.keys(data).forEach(function (key) {
      var g = data[key].category || "Other";
      if (!byGroup[g]) { byGroup[g] = []; groups.push(g); }
      byGroup[g].push(key);
    });
    if (!groups.length) { openBtn.style.display = "none"; return; }
    var active = groups[0];

    function renderTabs() {
      tabsEl.innerHTML = "";
      groups.forEach(function (g) {
        var b = document.createElement("button");
        b.type = "button";
        b.className = "cb-section-tab" + (g === active ? " is-active" : "");
        b.textContent = g;
        b.addEventListener("click", function () { active = g; renderTabs(); renderGrid(); });
        tabsEl.appendChild(b);
      });
    }

    function renderGrid() {
      gridEl.innerHTML = "";
      (byGroup[active] || []).forEach(function (key) {
        var p = data[key];
        var n = Array.isArray(p.blocks) ? p.blocks.length : 0;
        var card = document.createElement("button");
        card.type = "button";
        card.className = "cb-section-card";
        // Real rendered preview when the entry ships one (page templates);
        // otherwise fall back to the schematic block diagram (sections).
        var thumb = p.preview
          ? '<span class="cb-section-thumb cb-live-thumb"><iframe loading="lazy" scrolling="no" tabindex="-1" aria-hidden="true" srcdoc="' + esc(p.preview) + '"></iframe></span>'
          : '<span class="cb-section-thumb">' + thumbHtml(thumbFromBlocks(p.blocks)) + "</span>";
        card.innerHTML =
          thumb +
          '<span class="cb-section-name">' + esc(p.label || key) + "</span>" +
          '<span class="cb-section-meta">' + n + (n === 1 ? " block" : " blocks") + "</span>";
        card.addEventListener("click", function () {
          if (cfg.onPick(p)) close();
        });
        gridEl.appendChild(card);
      });
    }

    function open()  { renderTabs(); renderGrid(); modal.hidden = false; document.body.classList.add("cb-modal-open"); }
    function close() { modal.hidden = true; document.body.classList.remove("cb-modal-open"); }

    openBtn.addEventListener("click", open);
    Array.prototype.forEach.call(modal.querySelectorAll("[data-cb-close]"), function (el) {
      el.addEventListener("click", close);
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !modal.hidden) close();
    });
  }

  function cloneBlocks(blocks) {
    return Array.isArray(blocks) ? JSON.parse(JSON.stringify(blocks)) : [];
  }

  // Section library: append the chosen section's blocks to the end of the page.
  setupGallery({
    btnId: "cb-add-section", modalId: "cb-section-modal",
    tabsId: "cb-section-tabs", gridId: "cb-section-grid",
    data: PATTERNS,
    onPick: function (p) {
      cloneBlocks(p.blocks).forEach(function (b) { layout.push(b); });
      sync();
      var last = canvas.lastElementChild;
      if (last && last.scrollIntoView) last.scrollIntoView({ behavior: "smooth", block: "center" });
      return true;
    }
  });

  // Page-template library: replace the whole page (confirm if it has content).
  setupGallery({
    btnId: "cb-add-template", modalId: "cb-template-modal",
    tabsId: "cb-template-tabs", gridId: "cb-template-grid",
    data: window.CB_TEMPLATES || {},
    onPick: function (p) {
      if (layout.length && !window.confirm("Replace the current page with this template? Your existing blocks will be removed.")) {
        return false;
      }
      layout.length = 0;
      cloneBlocks(p.blocks).forEach(function (b) { layout.push(b); });
      // Designer pages ship as a full HTML document — switch the page's render
      // mode so it outputs verbatim (no theme shell) when saved.
      if (p.mode) {
        var rm = document.querySelector('select[name="cb_render_mode"]');
        if (rm && rm.value !== p.mode) {
          rm.value = p.mode;
          rm.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }
      sync();
      // The admin shell scrolls inside the floating .app-panel on desktop, but
      // the window on mobile — scroll whichever is actually the scroll container.
      var _panel = document.querySelector(".app-panel");
      var _scroller = (_panel && _panel.scrollHeight > _panel.clientHeight + 1) ? _panel : window;
      _scroller.scrollTo({ top: 0, behavior: "smooth" });
      return true;
    }
  });

  // Toggle the (collapsed-by-default) block palette.
  var _paletteToggle = document.getElementById("cb-add-block-toggle");
  if (_paletteToggle && palette) {
    _paletteToggle.addEventListener("click", function () {
      var show = palette.hasAttribute("hidden");
      if (show) palette.removeAttribute("hidden"); else palette.setAttribute("hidden", "");
      _paletteToggle.setAttribute("aria-expanded", show ? "true" : "false");
      _paletteToggle.classList.toggle("is-active", show);
    });
  }

  normalizeLayout();
  layoutInput.value = JSON.stringify(layout); // persist backfilled defaults on next save
  buildPalette();
  paint();
})();
