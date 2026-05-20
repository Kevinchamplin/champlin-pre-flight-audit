/**
 * WP 7 Readiness Check — admin interactions.
 *
 * - Animate the score ring on first paint.
 * - Wire per-finding "Fix automatically" buttons to admin-ajax.php.
 * - Wire top-level "Apply all fixes" button (sequential, per-fix progress).
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    animateScoreRing();
    wirePerFixButtons();
    wireApplyAllButton();
    wireRestoreButtons();
    wireRerunButton();
    wirePrintButton();
    wireOverrideButtons();
  });

  /* ============ Accept-as-known-risk override buttons ============ */
  function wireOverrideButtons() {
    var buttons = document.querySelectorAll('[data-wp7rc-override]');
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var findingId = btn.getAttribute('data-wp7rc-override');
        var action = btn.getAttribute('data-action') || 'accept';
        if (!findingId) return;
        if (action === 'accept' && !confirm('Mark "' + findingId + '" as an accepted known risk? It will no longer count toward your readiness score. You can un-accept it later from the same place.')) {
          return;
        }
        btn.disabled = true;
        btn.textContent = action === 'accept' ? 'Accepting…' : 'Removing…';
        ajaxPost('wp7rc_toggle_override', { finding_id: findingId, override_action: action })
          .then(function (json) {
            if (json && json.success) {
              // Reload so the score updates and the finding rerenders with the right state.
              window.location.reload();
            } else {
              alert((json && json.data && json.data.message) || 'Override action failed.');
              btn.disabled = false;
              btn.textContent = action === 'accept' ? 'Accept as known risk' : 'Un-accept (re-count in score)';
            }
          })
          .catch(function () {
            alert('Network error.');
            btn.disabled = false;
            btn.textContent = action === 'accept' ? 'Accept as known risk' : 'Un-accept (re-count in score)';
          });
      });
    });
  }

  /* ============ Re-run audit (visible loading state) ============ */
  function wireRerunButton() {
    var btn = document.querySelector('[data-wp7rc-rerun]');
    if (!btn) return;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var spin = btn.querySelector('.wp7rc-btn__icon--spin');
      if (spin) spin.hidden = false;
      btn.disabled = true;
      // Strip any URL hash so the page-top hero is visible after reload
      var url = window.location.pathname + window.location.search;
      window.location.replace(url);
    });
  }

  /* ============ Print (proper event so no inline handler quirks) ============ */
  function wirePrintButton() {
    var btn = document.querySelector('[data-wp7rc-print]');
    if (!btn) return;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      window.print();
    });
  }

  /* ============ Score ring animation ============ */
  function animateScoreRing() {
    var ring = document.querySelector('.wp7rc-score__ring circle:nth-of-type(2)');
    if (!ring) return;
    var finalDash = ring.getAttribute('stroke-dasharray');
    ring.setAttribute('stroke-dasharray', '0 490');
    void ring.getBoundingClientRect();
    requestAnimationFrame(function () {
      ring.style.transition = 'stroke-dasharray 1100ms cubic-bezier(0.22, 1, 0.36, 1)';
      ring.setAttribute('stroke-dasharray', finalDash);
    });
  }

  /* ============ Per-finding Fix buttons ============ */
  function wirePerFixButtons() {
    var buttons = document.querySelectorAll('[data-wp7rc-fix]');
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var fixId = btn.getAttribute('data-wp7rc-fix');
        if (!fixId) return;
        runFix(fixId, btn);
      });
    });
  }

  /* ============ Apply-all button ============ */
  function wireApplyAllButton() {
    var btn = document.querySelector('[data-wp7rc-apply-all]');
    if (!btn) return;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var msg = [
        'Apply all available fixes? Here is exactly what will happen:',
        '',
        '1. Major auto-updates: disabled (option flip, reversible from Dashboard → Updates).',
        '2. In-admin file editing: locked down via a new mu-plugin file (delete the mu-plugin to undo).',
        '3. All pending plugin updates: applied. A plugin-directory snapshot will be taken FIRST for each plugin, so you can restore the previous version if an update breaks your site.',
        '4. Two-Factor plugin: installed and activated (deactivate + delete to undo).',
        '',
        'Database is NOT backed up by this action — only plugin files. For full safety, take a separate database backup before continuing.',
        '',
        'Continue?'
      ].join('\n');
      if (!confirm(msg)) {
        return;
      }
      runApplyAll(btn);
    });
  }

  /* ============ Restore snapshot buttons ============ */
  function wireRestoreButtons() {
    var buttons = document.querySelectorAll('[data-wp7rc-restore]');
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var snapId = btn.getAttribute('data-wp7rc-restore');
        if (!snapId) return;
        if (!confirm('Restore this plugin to the snapshotted version? The CURRENT version will be replaced. A recovery snapshot of the current version will be taken automatically before the restore so you can roll forward again if needed.')) {
          return;
        }
        runRestore(snapId, btn);
      });
    });
  }

  function runRestore(snapId, btn) {
    var row    = btn.closest('.wp7rc-snapshot__actions');
    var status = row ? row.querySelector('.wp7rc-fix-status') : null;
    setButtonState(btn, 'fixing', 'Restoring…');
    setStatus(status, '', '');
    ajaxPost('wp7rc_restore_snapshot', { snapshot_id: snapId })
      .then(function (json) {
        if (json && json.success) {
          setButtonState(btn, 'success', '✓ Restored');
          setStatus(status, 'success', (json.data && json.data.message) || 'Restored.');
        } else {
          setButtonState(btn, 'error', 'Retry');
          setStatus(status, 'error', (json && json.data && json.data.message) || 'Restore failed.');
        }
      })
      .catch(function (err) {
        setButtonState(btn, 'error', 'Retry');
        setStatus(status, 'error', (err && err.message) || 'Network error.');
      });
  }

  /* ============ Run a single fix via AJAX ============ */
  function runFix(fixId, btn) {
    // Friendly per-fix confirmation
    var msg = {
      update_all_plugins: 'Update all pending plugins now? A plugin-directory snapshot will be taken first for each plugin, so you can restore the previous version if anything breaks. Database is not backed up by this action.',
      install_two_factor: 'Install and activate the official Two-Factor plugin from WordPress.org? Each administrator user will then configure 2FA under their profile.',
      disallow_file_edit: 'Drop a mu-plugin that defines DISALLOW_FILE_EDIT? This removes the Theme File Editor and Plugin File Editor from wp-admin. Reversible by deleting wp-content/mu-plugins/champlin-disallow-file-edit.php.',
      disable_major_auto_updates: 'Disable major-version auto-updates? Maintenance and security releases will still auto-install. Reversible from Dashboard → Updates.',
    }[fixId];
    if (msg && !confirm(msg)) {
      return;
    }
    var row    = btn.closest('[data-wp7rc-fix-row]');
    var status = row ? row.querySelector('.wp7rc-fix-status') : null;
    setButtonState(btn, 'fixing', 'Applying…');
    setStatus(status, '', '');

    ajaxPost('wp7rc_apply_fix', { fix_id: fixId })
      .then(function (json) {
        if (json && json.success) {
          setButtonState(btn, 'success', '✓ Fixed');
          setStatus(status, 'success', (json.data && json.data.message) || 'Applied.');
          markRowResolved(row);
        } else {
          var msg = (json && json.data && json.data.message) || 'Fix failed.';
          setButtonState(btn, 'error', 'Retry');
          setStatus(status, 'error', msg);
        }
      })
      .catch(function (err) {
        setButtonState(btn, 'error', 'Retry');
        setStatus(status, 'error', err && err.message ? err.message : 'Network error.');
      });
  }

  /* ============ Apply all fixes sequentially ============ */
  function runApplyAll(btn) {
    setButtonState(btn, 'fixing', 'Applying…');
    var perFixButtons = document.querySelectorAll('[data-wp7rc-fix]');
    perFixButtons.forEach(function (b) { setButtonState(b, 'fixing', 'Queued…'); });

    var report     = document.querySelector('[data-wp7rc-fix-report]');
    var reportList = document.querySelector('[data-wp7rc-fix-report-list]');
    if (reportList) { while (reportList.firstChild) reportList.removeChild(reportList.firstChild); }
    if (report)     { report.hidden = true; }

    ajaxPost('wp7rc_apply_all_fixes', {})
      .then(function (json) {
        if (!json || !json.success) {
          setButtonState(btn, 'error', 'Retry');
          return;
        }
        var results = (json.data && json.data.results) || {};
        var successCount = 0;
        var skipCount = 0;
        var failCount = 0;
        var reportRows = [];

        // Update each per-fix button + collect report rows
        perFixButtons.forEach(function (b) {
          var fid = b.getAttribute('data-wp7rc-fix');
          var r   = results[fid];
          var row    = b.closest('[data-wp7rc-fix-row]');
          var status = row ? row.querySelector('.wp7rc-fix-status') : null;
          var findingEl = b.closest('.wp7rc-finding');
          var labelEl   = findingEl ? findingEl.querySelector('.wp7rc-finding__label') : null;
          var label     = labelEl ? labelEl.textContent.trim() : fid;

          if (!r) {
            setButtonState(b, 'idle', 'Fix automatically');
            return;
          }
          if (r.status === 'success') {
            setButtonState(b, 'success', '✓ Fixed');
            setStatus(status, 'success', r.message || 'Applied.');
            markRowResolved(row);
            successCount++;
            reportRows.push({ kind: 'success', label: label, message: r.message || 'Applied.' });
          } else if (r.status === 'skip') {
            setButtonState(b, 'success', '— Already OK');
            setStatus(status, 'info', r.message || 'No change needed.');
            skipCount++;
            reportRows.push({ kind: 'info', label: label, message: r.message || 'No change needed.' });
          } else {
            setButtonState(b, 'error', 'Retry');
            setStatus(status, 'error', r.message || 'Fix failed.');
            failCount++;
            reportRows.push({ kind: 'error', label: label, message: r.message || 'Fix failed.' });
          }
        });

        // Top-level button label with full breakdown
        setButtonState(
          btn,
          failCount === 0 ? 'success' : 'error',
          failCount === 0
            ? '✓ ' + successCount + ' applied' + (skipCount ? ' · ' + skipCount + ' already OK' : '')
            : (successCount + ' applied · ' + failCount + ' failed' + (skipCount ? ' · ' + skipCount + ' already OK' : ''))
        );

        // Render full report below the buttons
        renderFixReport(reportList, reportRows);
        if (report) {
          report.hidden = false;
          if (failCount > 0) {
            report.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        }
      })
      .catch(function () {
        setButtonState(btn, 'error', 'Retry');
      });
  }

  function renderFixReport(listEl, rows) {
    if (!listEl) return;
    while (listEl.firstChild) listEl.removeChild(listEl.firstChild);
    rows.forEach(function (r) {
      var li = document.createElement('li');
      li.className = 'wp7rc-fix-report__row wp7rc-fix-report__row--' + r.kind;

      var icon = document.createElement('span');
      icon.className = 'wp7rc-fix-report__icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = r.kind === 'success' ? '✓' : (r.kind === 'error' ? '✗' : '—');

      var body = document.createElement('div');
      body.className = 'wp7rc-fix-report__body';

      var label = document.createElement('strong');
      label.className = 'wp7rc-fix-report__label';
      label.textContent = r.label;

      var msg = document.createElement('span');
      msg.className = 'wp7rc-fix-report__msg';
      msg.textContent = r.message;

      body.appendChild(label);
      body.appendChild(document.createTextNode(' — '));
      body.appendChild(msg);

      li.appendChild(icon);
      li.appendChild(body);
      listEl.appendChild(li);
    });
  }

  /* ============ Helpers ============ */
  function ajaxPost(action, data) {
    if (typeof wp7rcAjax === 'undefined') {
      return Promise.reject(new Error('AJAX config missing.'));
    }
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', wp7rcAjax.nonce);
    Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
    return fetch(wp7rcAjax.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  function setButtonState(btn, state, label) {
    if (!btn) return;
    btn.disabled = (state === 'fixing');
    btn.setAttribute('data-state', state);
    // Replace label text, preserving any leading icon span
    var icon = btn.querySelector('.wp7rc-btn__icon');
    btn.textContent = '';
    if (icon) {
      btn.appendChild(icon);
      btn.appendChild(document.createTextNode(' '));
    }
    btn.appendChild(document.createTextNode(label));
  }

  function setStatus(el, kind, message) {
    if (!el) return;
    el.className = 'wp7rc-fix-status' + (kind ? ' wp7rc-fix-status--' + kind : '');
    el.textContent = message || '';
  }

  function markRowResolved(row) {
    if (!row) return;
    var card = row.closest('.wp7rc-finding');
    if (card) card.classList.add('wp7rc-finding--resolved');
  }
})();
