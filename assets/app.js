"use strict";

/* =====================================================================
 * Mess Meal Sheet — front-end for the PHP + MySQL version.
 * All reads/writes go through api.php, nothing is kept in localStorage
 * except which tab you were looking at.
 * ===================================================================== */

const $ = id => document.getElementById(id);
const pad = n => String(n).padStart(2, "0");
const esc = s => String(s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const fmt = n => "৳ " + Number(n).toLocaleString("en-US", { maximumFractionDigits: 2 });
const MON = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const dateCell = d => { const [, m, dd] = d.split("-"); return `<span class="d-full">${d}</span><span class="d-short">${+dd} ${MON[m - 1]}</span>`; };
const costCell = n => `<span class="cur">৳ </span>${Number(n).toLocaleString("en-US", { maximumFractionDigits: 2 })}`;

const today = new Date();
const todayStr = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
const monthStr = `${today.getFullYear()}-${pad(today.getMonth() + 1)}`;

const state = {
  month: monthStr,
  members: [],
  meals: {},
  updates: {},        // "YYYY-MM-DD" -> memberId -> updated_at (version of that row)
  late: {},           // "YYYY-MM-DD" -> memberId -> how many changes after closing
  log: [],            // change history of the shown month
  logAvailable: false,
  policy: { open_from: todayStr, today: todayStr, is_admin: false },
  adminLoggedIn: false,
  adminToken: "",     // signed, expiring token for admin-only writes
};

try {
  state.adminToken = sessionStorage.getItem("mess_admin_token") || "";
} catch (e) { /* private mode */ }

/* ------------------------------ API layer ------------------------------ */

async function api(action, { method = "GET", query = {}, body = null, form = null } = {}) {
  const url = new URL("api.php", location.href);
  url.searchParams.set("action", action);
  for (const [k, v] of Object.entries(query)) {
    if (v !== null && v !== undefined && v !== "") url.searchParams.set(k, v);
  }

  const options = { method, headers: {} };
  if (state.adminToken) options.headers["X-Admin-Token"] = state.adminToken;
  if (form) {
    options.method = "POST";
    options.body = form;
  } else if (body) {
    options.method = "POST";
    options.headers["Content-Type"] = "application/json";
    options.body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(url, options);
  } catch (e) {
    throw new Error("Cannot reach the server. Is Apache/PHP running?");
  }

  let data = null;
  try {
    data = await response.json();
  } catch (e) {
    throw new Error(`Server returned HTTP ${response.status} instead of JSON. Open api.php in the browser for the PHP error.`);
  }

  if (!response.ok || data.ok === false) {
    const error = new Error(data && data.error ? data.error : `Request failed (HTTP ${response.status}).`);
    error.status = response.status;
    throw error;
  }
  return data;
}

/* ------------------------------ UI helpers ----------------------------- */

function showError(message) {
  const banner = $("banner");
  banner.innerHTML = esc(message);
  banner.classList.add("show");
}
function clearError() {
  $("banner").classList.remove("show");
}

/** Disable a button while an async action runs; surfaces failures in the banner. */
async function run(button, fn) {
  const wasDisabled = button ? button.disabled : false;
  if (button) button.disabled = true;
  try {
    clearError();
    return { ok: true, value: await fn() };
  } catch (e) {
    showError(e.message);
    return { ok: false, error: e };
  } finally {
    if (button) button.disabled = wasDisabled;
  }
}

function flash(button, text) {
  if (!button) return;
  button.dataset.label = button.dataset.label || button.textContent;
  button.textContent = text;
  setTimeout(() => { button.textContent = button.dataset.label; }, 1200);
}

function showPage(page) {
  document.querySelectorAll(".page").forEach(el => { el.hidden = el.id !== "page-" + page; });
  document.querySelectorAll(".tab").forEach(t => t.classList.toggle("active", t.dataset.page === page));
  try { localStorage.setItem("mess_tab", page); } catch (e) { /* private mode */ }
}

function fillSelects() {
  document.querySelectorAll(".people-select").forEach(sel => {
    const keep = sel.value;
    sel.innerHTML = state.members.map(m => `<option value="${m.id}">${esc(m.name)}</option>`).join("");
    if (keep && Array.from(sel.options).some(o => o.value === keep)) sel.value = keep;
  });
}

/* --------------------------- file preparation -------------------------- */

/** Shrink images in the browser (max 1100px, JPEG) and pass PDFs through. */
function prepareFile(input) {
  const file = input.files && input.files[0];
  if (!file) return Promise.resolve(null);

  if (file.type.startsWith("image/")) return compressImage(file);

  if (file.type === "application/pdf") {
    if (file.size > 5 * 1024 * 1024) return Promise.reject(new Error("PDF is too big (max 5 MB). Please upload a screenshot instead."));
    return Promise.resolve(file);
  }

  return Promise.reject(new Error("Only an image or a PDF file is allowed."));
}

function compressImage(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error("Could not read that file."));
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error("That image file looks broken."));
      img.onload = () => {
        const max = 1100;
        const scale = Math.min(1, max / Math.max(img.width, img.height));
        const canvas = document.createElement("canvas");
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        canvas.getContext("2d").drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(blob => {
          if (!blob) { reject(new Error("Could not process that image.")); return; }
          const name = String(file.name || "photo").replace(/\.[^.]+$/, "") + ".jpg";
          resolve(new File([blob], name, { type: "image/jpeg" }));
        }, "image/jpeg", 0.7);
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  });
}

/* ----------------------- attachments (view / delete) ------------------- */

function attachCell(file, table, id) {
  if (!file) return '<span style="color:#94a3b8">—</span>';
  if (file.kind === "image") {
    return `<img class="thumb" src="${esc(file.url)}" alt="voucher" onclick="viewFile('${table}',${id})">`;
  }
  return `<button class="btn-sm btn-view" onclick="viewFile('${table}',${id})">📄<span class="t-full"> PDF</span></button>`;
}

function viewFile(table, id) {
  const list = table === "bazar" ? state.bazar : state.deposits;
  const item = list.find(x => x.id === id);
  if (!item || !item.file) return;
  $("modalContent").innerHTML = item.file.kind === "image"
    ? `<img src="${esc(item.file.url)}" alt="attachment">`
    : `<iframe src="${esc(item.file.url)}" title="attachment"></iframe>`;
  $("modal").classList.add("show");
}

function closeModal() {
  $("modal").classList.remove("show");
  $("modalContent").innerHTML = "";
}

document.addEventListener("keydown", e => {
  if (e.key !== "Escape") return;
  closeModal();
  closeImport();
});

/* ------------------------------- loading ------------------------------- */

async function loadMonth(month) {
  const data = await api("data", { query: { month } });
  state.month = data.month;
  state.members = data.members;
  state.meals = data.meals || {};
  state.updates = data.meal_updates || {};
  state.late = data.late_edits || {};
  state.log = data.change_log || [];
  state.logAvailable = !!data.log_available;
  if (data.policy) state.policy = data.policy;
  state.adminLoggedIn = !!state.policy.is_admin;
  state.adminToken = state.policy.admin_token || "";
  state.bazar = data.bazar;
  state.deposits = data.deposits;

  ["month", "bzMonth", "dpMonth"].forEach(id => { if ($(id).value !== state.month) $(id).value = state.month; });

  fillSelects();
  applyAdminUI();
  setMealDateLimits();
  if ($("date").value && $("date").value < state.policy.today) $("date").value = state.policy.today;
  renderMeals();
  renderBazar();
  renderDeposit();
  renderChangeLog();
  loadAdminEntry();
}

async function goToMonth(month) {
  const result = await run(null, () => loadMonth(month));
  return result.ok;
}

/* ------------------------------ meal page ------------------------------ */

function mealOf(date, memberId) {
  const byMember = state.meals[date];
  const row = byMember ? byMember[String(memberId)] : null;
  return row || [0, 0, 0];
}

/** Version of that row as the server last reported it ("" when it does not exist yet). */
function mealVersion(date, memberId) {
  const byMember = state.updates[date];
  const version = byMember ? byMember[String(memberId)] : null;
  return version == null ? "" : String(version);
}

/** Is that day still open for boarders? The server decides, not the browser clock. */
function isDayOpen(date) {
  return !!date && date >= state.policy.open_from;
}

/* Which version of the day currently sits in the form: the server refuses a
   save if somebody else changed the row in the meantime. */
let currentVersion = "";

/** The main meal form is for today/future only; backdates belong to admin. */
function applyLockUI() {
  const date = $("date").value;
  const open = isDayOpen(date);

  ["morning", "noon", "night"].forEach(id => { $(id).disabled = !open; });

  const button = $("saveMealBtn");
  const label = open ? "Save Meal" : "🔒 Backdate is admin-only";
  button.dataset.label = label;
  button.textContent = label;
  button.disabled = !open;

  const note = $("lockNote");
  if (open) {
    note.hidden = true;
    note.innerHTML = "";
    return;
  }

  note.hidden = false;
  note.innerHTML = `🔒 <b>Past dates cannot be edited here.</b>
    Boarders can update meals from <code>${esc(state.policy.today)}</code> forward.
    Past-date corrections are restricted to the admin and recorded in the change log.
    <button type="button" class="btn-sm" onclick="openAdminPanel()">Open Admin Panel</button>`;
}

function loadEntry() {
  const date = $("date").value;
  const memberId = $("name").value;
  const [morning, noon, night] = mealOf(date, memberId);
  $("morning").checked = !!morning;
  $("noon").checked = !!noon;
  $("night").checked = !!night;
  currentVersion = mealVersion(date, memberId);
  applyLockUI();
}

let currentAdminVersion = "";

function setMealDateLimits() {
  const todayValue = state.policy.today || todayStr;
  $("date").min = todayValue;
  if (!$("adminDate").value) $("adminDate").value = todayValue;
}

function applyAdminUI() {
  const loginCard = $("adminLoginCard");
  const panel = $("adminPanel");
  if (!loginCard || !panel) return;

  loginCard.hidden = state.adminLoggedIn;
  panel.hidden = !state.adminLoggedIn;

  const warning = $("adminConfigWarning");
  warning.hidden = true;
  warning.innerHTML = "";
  if (!state.policy.admin_configured) {
    warning.hidden = false;
    warning.textContent = "Admin access is disabled. Set ADMIN_KEY in config.php to enable backdated corrections.";
  } else if (state.policy.admin_key_is_default) {
    warning.hidden = false;
    warning.textContent = "The default admin passcode is still active. Change ADMIN_KEY in config.php before using this site publicly.";
  }

  if (state.adminLoggedIn && !$("adminDate").value) {
    $("adminDate").value = state.policy.today || todayStr;
  }
}

function openAdminPanel() {
  showPage("admin");
  applyAdminUI();
  if (!state.adminLoggedIn) {
    refreshAdminConfig();
    if ($("adminKey")) $("adminKey").focus();
  }
}

async function refreshAdminConfig() {
  const result = await run(null, () => api("admin_config"));
  if (!result.ok) return;
  Object.assign(state.policy, result.value);
  applyAdminUI();
}

async function loginAdmin(event) {
  event.preventDefault();
  const key = $("adminKey").value.trim();
  if (!key) return;

  const result = await run($("adminLoginBtn"), () => api("admin_login", {
    body: { admin_key: key },
  }));
  if (!result.ok) return;

  state.adminLoggedIn = true;
  state.adminToken = result.value.token;
  state.policy.is_admin = true;
  state.policy.admin_token = result.value.token;
  try { sessionStorage.setItem("mess_admin_token", state.adminToken); } catch (e) { /* ignore */ }
  $("adminKey").value = "";
  applyAdminUI();
  loadAdminEntry();
}

async function logoutAdmin() {
  state.adminLoggedIn = false;
  state.adminToken = "";
  state.policy.is_admin = false;
  state.policy.admin_token = "";
  try { sessionStorage.removeItem("mess_admin_token"); } catch (e) { /* ignore */ }
  applyAdminUI();
}

function loadAdminEntry() {
  const date = $("adminDate") ? $("adminDate").value : "";
  const memberId = $("adminName") ? $("adminName").value : "";
  if (!date || !memberId) return;

  const [morning, noon, night] = mealOf(date, memberId);
  $("adminMorning").checked = !!morning;
  $("adminNoon").checked = !!noon;
  $("adminNight").checked = !!night;
  currentAdminVersion = mealVersion(date, memberId);
}

async function changeAdminDate() {
  const date = $("adminDate").value;
  if (!date) return;
  const month = date.slice(0, 7);
  if (month !== state.month) {
    const loaded = await goToMonth(month);
    if (!loaded) return;
  }
  loadAdminEntry();
}

async function saveAdminMeal() {
  if (!state.adminLoggedIn || !state.adminToken) {
    applyAdminUI();
    showError("Please sign in as an administrator first.");
    return;
  }

  const date = $("adminDate").value;
  const memberId = $("adminName").value;
  if (!date) { alert("Please select a date."); return; }
  if (!memberId) { alert("No member selected."); return; }

  const month = date.slice(0, 7);
  if (month !== state.month) {
    const loaded = await goToMonth(month);
    if (!loaded) return;
    loadAdminEntry();
  }

  const payload = {
    date,
    member_id: Number(memberId),
    morning: $("adminMorning").checked ? 1 : 0,
    noon: $("adminNoon").checked ? 1 : 0,
    night: $("adminNight").checked ? 1 : 0,
    version: currentAdminVersion,
    admin_token: state.adminToken,
  };

  const result = await run($("saveAdminMealBtn"), () => api("save_meal", { body: payload }));
  if (!result.ok) {
    if ([401, 419].includes(result.error.status)) {
      state.adminLoggedIn = false;
      state.adminToken = "";
      try { sessionStorage.removeItem("mess_admin_token"); } catch (e) { /* ignore */ }
      applyAdminUI();
    } else if (result.error.status === 409) {
      await goToMonth(month);
      loadAdminEntry();
    }
    return;
  }

  if (await goToMonth(month)) {
    loadAdminEntry();
    flash($("saveAdminMealBtn"), "Saved ✓");
  }
}

async function saveEntry() {
  const date = $("date").value;
  const memberId = $("name").value;
  if (!date) { alert("Please select a date."); return; }
  if (!memberId) { alert("No member selected."); return; }
  if (!isDayOpen(date)) { applyLockUI(); return; }

  const payload = {
    date,
    member_id: Number(memberId),
    morning: $("morning").checked ? 1 : 0,
    noon: $("noon").checked ? 1 : 0,
    night: $("night").checked ? 1 : 0,
    version: currentVersion,
  };

  const result = await run($("saveMealBtn"), () => api("save_meal", { body: payload }));
  if (!result.ok) {
    if ([401, 409].includes(result.error.status)) {
      await goToMonth(date.slice(0, 7));                            // show the other person's version
      loadEntry();
    }
    return;
  }

  const month = date.slice(0, 7);
  if (month !== state.month) $("month").value = month;
  if (await goToMonth(month)) {
    loadEntry();
    flash($("saveMealBtn"), "Saved ✓");
  }
}

function renderMeals() {
  const month = state.month;
  const [year, mon] = month.split("-").map(Number);
  const days = new Date(year, mon, 0).getDate();

  $("headRow").innerHTML =
    '<th>Date</th><th>Total<br><small>স / দু / রা</small></th>' +
    state.members.map(m => `<th>${esc(m.name)}</th>`).join("");

  let html = "";
  let sumMorning = 0, sumNoon = 0, sumNight = 0;
  const perMember = {};
  state.members.forEach(m => { perMember[m.id] = { morning: 0, noon: 0, night: 0 }; });

  for (let day = 1; day <= days; day++) {
    const date = `${year}-${pad(mon)}-${pad(day)}`;
    const weekday = new Date(year, mon - 1, day).getDay(); // 5 = Friday, 6 = Saturday
    const special = weekday === 5 || weekday === 6 ? "special" : "";
    const todayCell = date === state.policy.today ? "today" : "";

    let dailyMorning = 0, dailyNoon = 0, dailyNight = 0;
    state.members.forEach(m => {
      const [morning, noon, night] = mealOf(date, m.id);
      dailyMorning += morning;
      dailyNoon += noon;
      dailyNight += night;
    });

    const dayName = new Date(year, mon - 1, day).toLocaleDateString("en-US", { weekday: "short" });
    html += `<tr class="${special}">
      <td class="date ${todayCell}"><b>${day}</b> <small>${dayName}</small></td>
      <td class="daily-total ${todayCell}">
        <div class="daily-counts">
          <span>স ${dailyMorning}</span>
          <span>দু ${dailyNoon}</span>
          <span>রা ${dailyNight}</span>
        </div>
      </td>`;

    state.members.forEach(m => {
      const [morning, noon, night] = mealOf(date, m.id);
      if (morning) { sumMorning++; perMember[m.id].morning++; }
      if (noon) { sumNoon++; perMember[m.id].noon++; }
      if (night) { sumNight++; perMember[m.id].night++; }
      const lateCount = (state.late[date] && state.late[date][String(m.id)]) || 0;
      html += `<td class="person-cell ${todayCell}">
        <div class="marks">
          <span class="mark ${morning ? "on" : ""}" title="সকাল">${morning ? "স" : "—"}</span>
          <span class="mark ${noon ? "on" : ""}" title="দুপুর">${noon ? "দু" : "—"}</span>
          <span class="mark ${night ? "on" : ""}" title="রাত">${night ? "রা" : "—"}</span>
          ${lateCount ? `<span class="late-flag" title="${lateCount} change(s) after this day was closed">⚠</span>` : ""}
        </div>
      </td>`;
    });
    html += "</tr>";
  }

  $("tableBody").innerHTML = html;
  $("sumMorning").textContent = sumMorning;
  $("sumNoon").textContent = sumNoon;
  $("sumNight").textContent = sumNight;
  $("sumTotal").textContent = sumMorning + sumNoon + sumNight;

  $("memberSummary").innerHTML = state.members.map(m => {
    const c = perMember[m.id];
    return `<div class="member-stat">
      <div class="member-name">${esc(m.name)}</div>
      <div class="member-meals">স ${c.morning} &nbsp; দু ${c.noon} &nbsp; রা ${c.night}</div>
      <div class="member-total">Total ${c.morning + c.noon + c.night}</div>
    </div>`;
  }).join("");
}

/* ------------------------------ bazar page ----------------------------- */

function renderChangeLog() {
  const box = $("changeLogBox");
  if (!box) return;

  const lateCount = Object.values(state.late)
    .reduce((n, byMember) => n + Object.values(byMember).reduce((a, b) => a + b, 0), 0);
  box.querySelector("summary").textContent = lateCount
    ? `🕘 Change log — ⚠ ${lateCount} late change(s) this month`
    : "🕘 Change log — who changed which meal, and when";

  const marks = v => [v[0] ? "স" : "·", v[1] ? "দু" : "·", v[2] ? "রা" : "·"].join(" ");
  let html = "";

  if (!state.logAvailable) {
    html = '<tr><td class="empty" colspan="6">Import <code>migrate_meal_lock.sql</code> once to start recording changes.</td></tr>';
  } else if (!state.log.length) {
    html = '<tr><td class="empty" colspan="6">No meal change recorded for this month.</td></tr>';
  } else {
    html = state.log.map(c => `<tr class="${c.late ? "late-row" : ""}">
        <td>${dateCell(c.date)}</td>
        <td>${esc(c.name)}</td>
        <td class="log-before">${marks(c.old)}</td>
        <td>${marks(c.new)}</td>
        <td>${esc(c.at)}</td>
        <td>${c.late ? "⚠ " : ""}${c.source === "admin" ? "Admin" : "Boarder"}</td>
      </tr>`).join("");
  }

  ["changeLog", "adminChangeLog"].forEach(id => {
    if ($(id)) $(id).innerHTML = html;
  });
}

async function saveBazar() {
  const date = $("bzDate").value;
  const memberId = $("bzName").value;
  const item = $("bzItem").value.trim();
  const cost = parseFloat($("bzCost").value);

  if (!date) { alert("Please select a date."); return; }
  if (!item) { alert("Please write the meal item."); return; }
  if (isNaN(cost) || cost < 0) { alert("Please enter the total cost."); return; }

  const result = await run($("saveBazarBtn"), async () => {
    const form = new FormData();
    form.append("date", date);
    form.append("member_id", memberId);
    form.append("item", item);
    form.append("cost", cost);
    const file = await prepareFile($("bzFile"));
    if (file) form.append("file", file, file.name);
    return api("save_bazar", { form });
  });
  if (!result.ok) return;

  $("bzItem").value = "";
  $("bzCost").value = "";
  $("bzFile").value = "";
  const month = date.slice(0, 7);
  if (month !== state.month) $("bzMonth").value = month;
  if (await goToMonth(month)) flash($("saveBazarBtn"), "Saved ✓");
}

async function delBazar(id) {
  if (!confirm("Delete this bazar entry?")) return;
  const result = await run(null, () => api("delete_bazar", { body: { id } }));
  if (result.ok) await goToMonth(state.month);
}

function renderBazar() {
  const list = state.bazar;
  let total = 0;

  $("bzBody").innerHTML = list.length ? list.map(entry => {
    total += entry.value;
    return `<tr>
      <td>${dateCell(entry.date)}</td>
      <td>${esc(entry.name)}</td>
      <td>${esc(entry.item)}</td>
      <td class="num">${costCell(entry.value)}</td>
      <td>${attachCell(entry.file, "bazar", entry.id)}</td>
    </tr>`;
  }).join("") : '<tr><td class="empty" colspan="6">No bazar entry for this month.</td></tr>';

  $("bzFoot").innerHTML = list.length
    ? `<tr><td colspan="3">Total Bazar Cost</td><td class="num">${fmt(total)}</td><td colspan="2"></td></tr>`
    : "";
  $("bzTotal").textContent = fmt(total);
  $("bzCount").textContent = list.length;
}

/* ----------------------------- deposit page ---------------------------- */

async function saveDeposit() {
  const date = $("dpDate").value;
  const memberId = $("dpName").value;
  const amount = parseFloat($("dpAmount").value);

  if (!date) { alert("Please select a date."); return; }
  if (isNaN(amount) || amount <= 0) { alert("Please enter the deposit amount."); return; }

  const result = await run($("saveDepositBtn"), async () => {
    const form = new FormData();
    form.append("date", date);
    form.append("member_id", memberId);
    form.append("amount", amount);
    const file = await prepareFile($("dpFile"));
    if (file) form.append("file", file, file.name);
    return api("save_deposit", { form });
  });
  if (!result.ok) return;

  $("dpAmount").value = "";
  $("dpFile").value = "";
  const month = date.slice(0, 7);
  if (month !== state.month) $("dpMonth").value = month;
  if (await goToMonth(month)) flash($("saveDepositBtn"), "Saved ✓");
}

async function delDeposit(id) {
  if (!confirm("Delete this deposit entry?")) return;
  const result = await run(null, () => api("delete_deposit", { body: { id } }));
  if (result.ok) await goToMonth(state.month);
}

function renderDeposit() {
  const list = state.deposits;
  let total = 0;
  const per = {};
  state.members.forEach(m => { per[m.id] = 0; });

  $("dpBody").innerHTML = list.length ? list.map(entry => {
    total += entry.value;
    if (entry.member_id != null) per[entry.member_id] = (per[entry.member_id] || 0) + entry.value;
    return `<tr>
      <td>${dateCell(entry.date)}</td>
      <td>${esc(entry.name)}</td>
      <td class="num">${costCell(entry.value)}</td>
      <td>${attachCell(entry.file, "deposit", entry.id)}</td>
    </tr>`;
  }).join("") : '<tr><td class="empty" colspan="5">No deposit for this month.</td></tr>';

  $("dpFoot").innerHTML = list.length
    ? `<tr><td colspan="2">Total Deposit</td><td class="num">${fmt(total)}</td><td colspan="2"></td></tr>`
    : "";
  $("dpTotal").textContent = fmt(total);
  $("dpCount").textContent = list.length;

  $("dpMember").innerHTML = state.members.map(m => `<div class="member-stat">
      <div class="member-name">${esc(m.name)}</div>
      <div class="member-total">${fmt(per[m.id] || 0)}</div>
    </div>`).join("");
}

/* ------------------------------- import -------------------------------- */

function openImport() {
  if (!state.adminLoggedIn || !state.adminToken) {
    openAdminPanel();
    showError("Sign in as an administrator before importing data.");
    return;
  }
  $("importText").value = "";
  $("importModal").classList.add("show");
}
function closeImport() {
  $("importModal").classList.remove("show");
}

async function doImport() {
  const raw = $("importText").value.trim();
  if (!raw) { alert("Paste the exported JSON first."); return; }

  let payload;
  try {
    payload = JSON.parse(raw);
  } catch (e) {
    alert("That is not valid JSON: " + e.message);
    return;
  }
  if (!payload.meals && !payload.bazar && !payload.deposit) {
    alert('The JSON must contain "meals", "bazar" and/or "deposit" keys.');
    return;
  }

  payload.admin_token = state.adminToken;
  const result = await run($("importBtn"), () => api("import", { body: payload }));
  if (!result.ok) {
    if ([401, 419].includes(result.error.status)) {
      state.adminLoggedIn = false;
      state.adminToken = "";
      try { sessionStorage.removeItem("mess_admin_token"); } catch (e) { /* ignore */ }
      applyAdminUI();
    }
    return;
  }

  const counts = result.value.imported;
  alert(`Imported ${counts.meals} meal rows, ${counts.bazar} bazar entries and ${counts.deposits} deposits.`);
  closeImport();
  await goToMonth(state.month);
}

/* -------------------------------- init --------------------------------- */

function init() {
  ["date", "bzDate", "dpDate", "adminDate"].forEach(id => { $(id).value = todayStr; });
  ["month", "bzMonth", "dpMonth"].forEach(id => { $(id).value = monthStr; });
  setMealDateLimits();

  ["month", "bzMonth", "dpMonth"].forEach(id => {
    $(id).addEventListener("change", () => goToMonth($(id).value || monthStr));
  });
  ["date", "name"].forEach(id => $(id).addEventListener("change", loadEntry));
  $("adminDate").addEventListener("change", changeAdminDate);
  $("adminName").addEventListener("change", loadAdminEntry);

  let tab = "meal";
  try { tab = localStorage.getItem("mess_tab") || "meal"; } catch (e) { /* ignore */ }
  showPage(tab);

  /* Fill the form from the sheet as soon as the month arrives, so the boxes
     always show what is stored instead of looking "empty". */
  goToMonth(monthStr).then(ok => { if (ok) loadEntry(); });
}

init();
