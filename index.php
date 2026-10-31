<?php
declare(strict_types=1);
// Front-end only: all data comes from api.php (see assets/app.js).
?><!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mess Meal Sheet</title>
<link rel="stylesheet" href="assets/style.css?v=5">
</head>
<body>
<div class="container">
  <div class="page-heading">
    <div>
      <h1>🍽️ Mess Meal Sheet</h1>
      <div class="subtitle">Daily meal planning, bazar cost &amp; deposit for 7 mess members</div>
    </div>
    <button type="button" class="admin-access-btn" onclick="openAdminPanel()">🔐 Admin Login</button>
  </div>

  <div class="banner" id="banner"></div>

  <div class="tabs">
    <button class="tab active" data-page="meal" onclick="showPage('meal')">🍽️ Meal Sheet</button>
    <button class="tab" data-page="bazar" onclick="showPage('bazar')">🛒 Bazar Cost</button>
    <button class="tab" data-page="deposit" onclick="showPage('deposit')">💰 Money Deposit</button>
  </div>

  <!-- ================= PAGE 1: MEAL ================= -->
  <div id="page-meal" class="page">
    <div class="card form-card">
      <div class="form-title">➕ Meal Entry</div>
      <p class="admin-help">Today and future dates can be updated here. Past-date corrections are admin-only.</p>
      <div class="form">
        <div>
          <label>Date</label>
          <input type="date" id="date">
        </div>
        <div>
          <label>Name</label>
          <select id="name" class="people-select"></select>
        </div>
        <div class="meal-area">
          <label>Meal Select</label>
          <div class="meals">
            <label class="mealbox"><input type="checkbox" id="morning">সকাল</label>
            <label class="mealbox"><input type="checkbox" id="noon">দুপুর</label>
            <label class="mealbox"><input type="checkbox" id="night">রাত</label>
          </div>
        </div>
        <button id="saveMealBtn" onclick="saveEntry()">Save Meal</button>
      </div>
      <div class="lock-note" id="lockNote" hidden></div>
    </div>

    <div class="card">
      <div class="toolbar">
        <h2>📅 Monthly Meal Table</h2>
        <div class="month-control">
          <label style="margin:0">Month:</label>
          <input type="month" id="month">
          <button onclick="window.print()">🖨 Print</button>
        </div>
      </div>

      <div class="table-wrap">
        <table class="meal-table">
          <thead>
            <tr id="headRow"></tr>
          </thead>
          <tbody id="tableBody"></tbody>
        </table>
      </div>

      <div class="legend">
        <span>🟢 Selected</span>
        <span>⚪ Not selected</span>
        <span>🟧 Friday &amp; Saturday (Special day)</span>
        <span>⚠️ Changed after the day was closed</span>
        <span>Format: <b>স</b> = সকাল, <b>দু</b> = দুপুর, <b>রা</b> = রাত</span>
      </div>

      <div class="summary">
        <div class="stat"><span>সকাল</span><b id="sumMorning">0</b></div>
        <div class="stat"><span>দুপুর</span><b id="sumNoon">0</b></div>
        <div class="stat"><span>রাত</span><b id="sumNight">0</b></div>
        <div class="stat"><span>Total Meal</span><b id="sumTotal">0</b></div>
      </div>

      <div class="member-summary">
        <div class="member-summary-title">👥 Member-wise Monthly Total</div>
        <div class="member-grid" id="memberSummary"></div>
      </div>

      <details class="change-log" id="changeLogBox">
        <summary>🕘 Change log — who changed which meal, and when</summary>
        <div class="table-wrap">
          <table class="plain-table log-table">
            <thead>
              <tr><th>Day</th><th>Member</th><th>Before</th><th>After</th><th>Changed at</th><th>By</th></tr>
            </thead>
            <tbody id="changeLog"></tbody>
          </table>
        </div>
      </details>
    </div>
  </div>

  <!-- ================= PAGE 2: BAZAR ================= -->
  <div id="page-bazar" class="page" hidden>
    <div class="card form-card">
      <div class="form-title">🛒 Bazar Entry</div>
      <div class="form bazar">
        <div>
          <label>Date</label>
          <input type="date" id="bzDate">
        </div>
        <div>
          <label>Name (কে বাজার করেছে)</label>
          <select id="bzName" class="people-select"></select>
        </div>
        <div class="wide">
          <label>Meal Item</label>
          <input type="text" id="bzItem" placeholder="e.g. চাল, ডাল, মাছ, সবজি">
        </div>
        <div>
          <label>Total Cost (৳)</label>
          <input type="number" id="bzCost" min="0" step="any" placeholder="0">
        </div>
        <div class="wide">
          <label>Voucher Upload</label>
          <input type="file" id="bzFile" accept="image/*,application/pdf">
        </div>
        <button id="saveBazarBtn" onclick="saveBazar()">Save Bazar</button>
      </div>
    </div>

    <div class="card">
      <div class="toolbar">
        <h2>🧾 Bazar Cost List</h2>
        <div class="month-control">
          <label style="margin:0">Month:</label>
          <input type="month" id="bzMonth">
          <button onclick="window.print()">🖨 Print</button>
        </div>
      </div>
      <div class="summary two">
        <div class="stat"><span>Total Bazar Cost</span><b id="bzTotal">৳ 0</b></div>
        <div class="stat"><span>Entries</span><b id="bzCount">0</b></div>
      </div>
      <div class="table-wrap">
        <table class="plain-table bz-table">
          <thead>
            <tr><th>Date</th><th>Name</th><th>Item</th><th class="num">Cost</th><th>Voucher</th><th></th></tr>
          </thead>
          <tbody id="bzBody"></tbody>
          <tfoot id="bzFoot"></tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- ================= PAGE 3: DEPOSIT ================= -->
  <div id="page-deposit" class="page" hidden>
    <div class="card form-card">
      <div class="form-title">💰 Money Deposit</div>
      <div class="form deposit">
        <div>
          <label>Date</label>
          <input type="date" id="dpDate">
        </div>
        <div>
          <label>Name</label>
          <select id="dpName" class="people-select"></select>
        </div>
        <div>
          <label>Deposit Amount (৳)</label>
          <input type="number" id="dpAmount" min="0" step="any" placeholder="0">
        </div>
        <div class="wide">
          <label>Attachment</label>
          <input type="file" id="dpFile" accept="image/*,application/pdf">
        </div>
        <button id="saveDepositBtn" onclick="saveDeposit()">Save Deposit</button>
      </div>
    </div>

    <div class="card">
      <div class="toolbar">
        <h2>🏦 Deposit List</h2>
        <div class="month-control">
          <label style="margin:0">Month:</label>
          <input type="month" id="dpMonth">
          <button onclick="window.print()">🖨 Print</button>
        </div>
      </div>
      <div class="summary two">
        <div class="stat"><span>Total Deposit</span><b id="dpTotal">৳ 0</b></div>
        <div class="stat"><span>Entries</span><b id="dpCount">0</b></div>
      </div>
      <div class="table-wrap">
        <table class="plain-table dp-table">
          <thead>
            <tr><th>Date</th><th>Name</th><th class="num">Amount</th><th>Attachment</th><th></th></tr>
          </thead>
          <tbody id="dpBody"></tbody>
          <tfoot id="dpFoot"></tfoot>
        </table>
      </div>

      <div class="member-summary">
        <div class="member-summary-title">👥 Member-wise Monthly Deposit</div>
        <div class="dep-grid" id="dpMember"></div>
      </div>
    </div>
  </div>

  <!-- ================= PAGE 4: ADMIN ================= -->
  <div id="page-admin" class="page" hidden>
    <div class="card admin-login" id="adminLoginCard">
      <div class="form-title">🔐 Admin sign in</div>
      <p class="admin-help">
        Boarders can update meals for today and future dates. Signing in here allows
        an administrator to correct past-date meals and import old data.
      </p>
      <div class="admin-config-warning" id="adminConfigWarning" hidden></div>
      <form class="admin-login-form" onsubmit="loginAdmin(event)">
        <div>
          <label for="adminKey">Admin passcode</label>
          <input type="password" id="adminKey" autocomplete="current-password" required>
        </div>
        <button type="submit" id="adminLoginBtn">Sign in</button>
      </form>
    </div>

    <div id="adminPanel" hidden>
      <div class="card admin-status">
        <div>
          <b>🔓 Administrator mode</b>
          <span>Backdated meal changes are recorded in the change log.</span>
        </div>
        <button type="button" class="btn-secondary" onclick="logoutAdmin()">Sign out</button>
      </div>

      <div class="card form-card">
        <div class="form-title">🕘 Backdate meal correction</div>
        <div class="form admin-meal-form">
          <div>
            <label for="adminDate">Date</label>
            <input type="date" id="adminDate">
          </div>
          <div>
            <label for="adminName">Name</label>
            <select id="adminName" class="people-select"></select>
          </div>
          <div class="meal-area">
            <label>Meal Select</label>
            <div class="meals">
              <label class="mealbox"><input type="checkbox" id="adminMorning">সকাল</label>
              <label class="mealbox"><input type="checkbox" id="adminNoon">দুপুর</label>
              <label class="mealbox"><input type="checkbox" id="adminNight">রাত</label>
            </div>
          </div>
          <button type="button" id="saveAdminMealBtn" onclick="saveAdminMeal()">Save Change</button>
        </div>
        <div class="admin-help admin-edit-note">
          Past, current, and future dates are available here. Each saved change is
          attributed to the admin.
        </div>
      </div>

      <div class="card">
        <div class="toolbar">
          <h2>📋 Recent meal changes</h2>
          <button type="button" class="btn-secondary" onclick="openImport()">⇪ Import old data</button>
        </div>
        <div class="table-wrap">
          <table class="plain-table log-table">
            <thead>
              <tr><th>Day</th><th>Member</th><th>Before</th><th>After</th><th>Changed at</th><th>By</th></tr>
            </thead>
            <tbody id="adminChangeLog"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- attachment viewer -->
<div class="modal" id="modal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <button onclick="closeModal()">✕ Close</button>
    <div id="modalContent"></div>
  </div>
</div>

<!-- import helper for data saved by the old localStorage version -->
<div class="modal" id="importModal" onclick="if(event.target===this)closeImport()">
  <div class="modal-box">
    <div class="modal-head">
      <b>Import data from the old HTML file</b>
      <button onclick="closeImport()">✕</button>
    </div>
    <p style="margin:0;font-size:13px;color:#475569;max-width:900px">
      Open the old <code>mess_meal_sheet_v3.html</code> page in the same browser, press F12 → Console, paste the
      snippet from the README, copy the printed JSON and paste it below.
    </p>
    <textarea id="importText" spellcheck="false" placeholder='{"meals":{...},"bazar":[...],"deposit":[...]}'></textarea>
    <div class="modal-actions">
      <button id="importBtn" onclick="doImport()">Import</button>
    </div>
  </div>
</div>

<script src="assets/app.js?v=5" defer></script>
</body>
</html>
