<?php
/**
 * Admin dashboard view — premium agency-grade visual.
 * Expects $results from the runner.
 *
 * @package WP7ReadinessCheck
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// This file is included from wp7rc_render_dashboard() in the main plugin file.
// All variables below are function-scope locals, NOT globals. Plugin Check can't
// see the surrounding function context when analyzing the view in isolation.

/** @var array $results */
$score    = (int)   ($results['score'] ?? 0);
$summary  = (array) ($results['summary'] ?? []);
$findings = (array) ($results['results'] ?? []);
$is_post  = wp7rc_is_post_seven();

// Group findings by category
$by_cat = [];
foreach ($findings as $r) {
    $cat = $r['category'] ?? 'other';
    if (!isset($by_cat[$cat])) {
        $by_cat[$cat] = [];
    }
    $by_cat[$cat][] = $r;
}
$categories = wp7rc_categories();
uksort($by_cat, static function ($a, $b) use ($categories) {
    return ($categories[$a]['order'] ?? 99) <=> ($categories[$b]['order'] ?? 99);
});

// Score ring color
$ring_color = $score >= 90 ? '#22c55e' : ($score >= 70 ? '#eab308' : ($score >= 50 ? '#f97316' : '#ef4444'));
$score_label = $score >= 90 ? 'Production-ready' : ($score >= 70 ? 'Mostly ready' : ($score >= 50 ? 'Significant work needed' : 'Not ready'));

// Pre-resolve which fixes are available (so we know to render the Apply-all button)
require_once WP7RC_DIR . 'includes/fixes.php';
require_once WP7RC_DIR . 'includes/snapshots.php';
$available_fix_ids = [];
foreach ($findings as $r) {
    $fid = $r['fix_id'] ?? '';
    if ($fid !== '' && wp7rc_fix_available($fid) && !in_array($fid, $available_fix_ids, true)) {
        $available_fix_ids[] = $fid;
    }
}
$has_any_fix = $available_fix_ids !== [];

// Backup-plugin posture for the safety banner
$backup_finding = null;
foreach ($findings as $r) {
    if (($r['id'] ?? '') === 'backup_plugin') {
        $backup_finding = $r;
        break;
    }
}
$backup_ok = $backup_finding && ($backup_finding['status'] ?? '') === 'pass';

// Snapshots inventory
$snapshots = wp7rc_list_snapshots();

// Donut math: circumference = 2πr. r=78 → C≈490
$circ = 490;
$dash = (int) round($circ * ($score / 100));
?>
<div class="wp7rc-wrap">

  <!-- =========== HERO =========== -->
  <header class="wp7rc-hero">
    <div class="wp7rc-hero__brand">
      <span class="wp7rc-dot"></span>
      <span>WordPress 7 Readiness Check <strong>by Champlin Enterprises</strong></span>
    </div>
    <h1>
      <?php if ($is_post): ?>
        Post-flight verification
      <?php else: ?>
        Pre-flight audit
      <?php endif; ?>
    </h1>
    <p class="wp7rc-hero__sub">
      <?php if ($is_post): ?>
        You are on WordPress 7.0+. This audit verifies the upgrade landed cleanly and surfaces issues that may have crept in.
      <?php else: ?>
        WordPress 7.0 readiness across server, plugins, custom code, headless surfaces, multisite, and security. <?php echo count($findings); ?> automated checks across <?php echo count($by_cat); ?> categories.
      <?php endif; ?>
    </p>
  </header>

  <!-- =========== SAFETY BANNER =========== -->
  <?php if ($has_any_fix): ?>
    <section class="wp7rc-safety wp7rc-safety--<?php echo $backup_ok ? 'ok' : 'warn'; ?>">
      <div class="wp7rc-safety__icon" aria-hidden="true">
        <?php if ($backup_ok): ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M5 13l4 4L19 7"/></svg>
        <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M12 9v4 M12 17h.01 M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
        <?php endif; ?>
      </div>
      <div class="wp7rc-safety__body">
        <strong>Safety check:</strong>
        <?php if ($backup_ok): ?>
          Backup plugin detected — <?php echo esc_html($backup_finding['value'] ?? ''); ?>. Confirm your latest backup is recent before applying fixes.
        <?php else: ?>
          No backup plugin detected. Plugin updates carry real risk; install a backup tool (UpdraftPlus, BackWPup, or your host's snapshot service) for full database protection before applying fixes. WP 7 Readiness automatically snapshots plugin files before updates, but does not back up your database.
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- =========== SCORE + SUMMARY =========== -->
  <section class="wp7rc-summary">
    <div class="wp7rc-score">
      <svg viewBox="0 0 180 180" class="wp7rc-score__ring" aria-hidden="true">
        <circle cx="90" cy="90" r="78" stroke="#e2e8f0" stroke-width="14" fill="none"></circle>
        <circle cx="90" cy="90" r="78" stroke="<?php echo esc_attr($ring_color); ?>" stroke-width="14" fill="none"
                stroke-dasharray="<?php echo esc_attr((string) $dash); ?> <?php echo esc_attr((string) ($circ - $dash)); ?>"
                stroke-dashoffset="0"
                stroke-linecap="round"
                transform="rotate(-90 90 90)"></circle>
      </svg>
      <div class="wp7rc-score__inner">
        <div class="wp7rc-score__num"><?php echo esc_html((string) $score); ?></div>
        <div class="wp7rc-score__unit">/100</div>
      </div>
    </div>

    <div class="wp7rc-summary__meta">
      <p class="wp7rc-summary__verdict" style="color: <?php echo esc_attr($ring_color); ?>;"><?php echo esc_html($score_label); ?></p>
      <ul class="wp7rc-pills">
        <li class="wp7rc-pill wp7rc-pill--pass"><span><?php echo esc_html((string) ($summary['pass'] ?? 0)); ?></span> passing</li>
        <li class="wp7rc-pill wp7rc-pill--warn"><span><?php echo esc_html((string) ($summary['warn'] ?? 0)); ?></span> warnings</li>
        <li class="wp7rc-pill wp7rc-pill--fail"><span><?php echo esc_html((string) ($summary['fail'] ?? 0)); ?></span> failures</li>
        <li class="wp7rc-pill wp7rc-pill--info"><span><?php echo esc_html((string) (($summary['info'] ?? 0) + ($summary['skip'] ?? 0))); ?></span> informational</li>
      </ul>
      <p class="wp7rc-summary__hint">
        <?php if ($score >= 90): ?>
          Strong readiness. Walk the warnings below; most will be 5-minute fixes. Then schedule the upgrade.
        <?php elseif ($score >= 70): ?>
          Largely ready. The warnings + failures below need triage before update day. Plan a remediation window.
        <?php elseif ($score >= 50): ?>
          Meaningful gaps. Treat the failures below as upgrade-blockers; address before scheduling the upgrade.
        <?php else: ?>
          Significant readiness gaps. Do not run the WordPress 7.0 upgrade until the failures below are resolved.
        <?php endif; ?>
      </p>
      <div class="wp7rc-actions">
        <?php if ($has_any_fix): ?>
          <button class="wp7rc-btn wp7rc-btn--accent" data-wp7rc-apply-all="1">
            <span class="wp7rc-btn__icon" aria-hidden="true">⚡</span>
            Apply <?php echo count($available_fix_ids); ?> available fix<?php echo count($available_fix_ids) === 1 ? '' : 'es'; ?>
          </button>
        <?php else: ?>
          <span class="wp7rc-fix-empty" title="Re-run the audit to verify nothing has regressed.">
            <span aria-hidden="true">✓</span> All available fixes applied
          </span>
        <?php endif; ?>
        <button class="wp7rc-btn wp7rc-btn--primary" data-wp7rc-rerun="1">
          <span class="wp7rc-btn__icon wp7rc-btn__icon--spin" aria-hidden="true" hidden>↻</span>
          Re-run audit
        </button>
        <button class="wp7rc-btn wp7rc-btn--ghost" data-wp7rc-print="1">Print / Save as PDF</button>
      </div>
      <?php if ($has_any_fix): ?>
        <div class="wp7rc-fix-report" data-wp7rc-fix-report hidden>
          <h4 class="wp7rc-fix-report__title">Fix results</h4>
          <ul class="wp7rc-fix-report__list" data-wp7rc-fix-report-list></ul>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- =========== CATEGORY-GROUPED FINDINGS =========== -->
  <main class="wp7rc-categories">
    <?php foreach ($by_cat as $cat_slug => $items): ?>
      <?php
        $cat_label = $categories[$cat_slug]['label'] ?? ucfirst($cat_slug);
        $cat_summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
        foreach ($items as $r) {
            $st = $r['status'] ?? 'info';
            if (isset($cat_summary[$st])) {
                $cat_summary[$st]++;
            }
        }
      ?>
      <section class="wp7rc-category">
        <header class="wp7rc-category__head">
          <h2><?php echo esc_html($cat_label); ?></h2>
          <div class="wp7rc-category__counts">
            <?php if ($cat_summary['fail']): ?><span class="wp7rc-tag wp7rc-tag--fail"><?php echo esc_html((string) $cat_summary['fail']); ?> fail</span><?php endif; ?>
            <?php if ($cat_summary['warn']): ?><span class="wp7rc-tag wp7rc-tag--warn"><?php echo esc_html((string) $cat_summary['warn']); ?> warn</span><?php endif; ?>
            <?php if ($cat_summary['pass']): ?><span class="wp7rc-tag wp7rc-tag--pass"><?php echo esc_html((string) $cat_summary['pass']); ?> pass</span><?php endif; ?>
            <?php if ($cat_summary['info']): ?><span class="wp7rc-tag wp7rc-tag--info"><?php echo esc_html((string) $cat_summary['info']); ?> info</span><?php endif; ?>
          </div>
        </header>

        <ul class="wp7rc-findings">
          <?php foreach ($items as $r): ?>
            <?php
              $status = $r['status'] ?? 'info';
              $is_overridden = !empty($r['overridden']);
              $icon_class = 'wp7rc-icon wp7rc-icon--' . ($is_overridden ? 'accepted' : $status);
              $row_class  = 'wp7rc-finding wp7rc-finding--' . $status . ($is_overridden ? ' wp7rc-finding--overridden' : '');
            ?>
            <li class="<?php echo esc_attr($row_class); ?>">
              <div class="<?php echo esc_attr($icon_class); ?>" aria-hidden="true">
                <?php if ($status === 'pass'): ?><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7" stroke-width="2.5"/></svg>
                <?php elseif ($status === 'warn'): ?><svg viewBox="0 0 24 24"><path d="M12 2L2 22h20L12 2z M12 9v6 M12 18v.5" stroke-width="2"/></svg>
                <?php elseif ($status === 'fail'): ?><svg viewBox="0 0 24 24"><path d="M6 6l12 12 M18 6L6 18" stroke-width="2.5"/></svg>
                <?php else: ?><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-width="2"/><path d="M12 8v.5 M12 11v6" stroke-width="2.5"/></svg>
                <?php endif; ?>
              </div>
              <div class="wp7rc-finding__body">
                <div class="wp7rc-finding__row">
                  <h3 class="wp7rc-finding__label"><?php echo esc_html($r['label'] ?? ''); ?></h3>
                  <?php if (!empty($r['value'])): ?>
                    <code class="wp7rc-finding__value"><?php echo esc_html($r['value']); ?></code>
                  <?php endif; ?>
                  <?php if ($is_overridden): ?>
                    <span class="wp7rc-finding__override-tag">Accepted risk</span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($r['message'])): ?>
                  <p class="wp7rc-finding__message"><?php echo esc_html($r['message']); ?></p>
                <?php endif; ?>
                <?php if (!empty($r['remediation'])): ?>
                  <p class="wp7rc-finding__remedy">
                    <strong>Fix:</strong> <?php echo esc_html($r['remediation']); ?>
                  </p>
                <?php endif; ?>
                <?php
                  $fid = $r['fix_id'] ?? '';
                  if ($fid !== '' && wp7rc_fix_available($fid)):
                ?>
                  <div class="wp7rc-finding__fix" data-wp7rc-fix-row="<?php echo esc_attr($fid); ?>">
                    <button class="wp7rc-btn wp7rc-btn--accent wp7rc-btn--sm" data-wp7rc-fix="<?php echo esc_attr($fid); ?>">
                      <span class="wp7rc-btn__icon" aria-hidden="true">⚡</span> Fix automatically
                    </button>
                    <span class="wp7rc-fix-status" role="status" aria-live="polite"></span>
                  </div>
                <?php endif; ?>
                <?php if (in_array($status, ['warn', 'fail'], true) || $is_overridden): ?>
                  <div class="wp7rc-finding__override" data-wp7rc-override-row="<?php echo esc_attr($r['id'] ?? ''); ?>">
                    <?php if ($is_overridden): ?>
                      <button class="wp7rc-override-btn wp7rc-override-btn--undo" data-wp7rc-override="<?php echo esc_attr($r['id'] ?? ''); ?>" data-action="unaccept">
                        Un-accept (re-count in score)
                      </button>
                      <span class="wp7rc-override-meta">
                        Accepted <?php echo esc_html((string) ($r['override_meta']['accepted_at'] ?? '')); ?>
                      </span>
                    <?php else: ?>
                      <button class="wp7rc-override-btn" data-wp7rc-override="<?php echo esc_attr($r['id'] ?? ''); ?>" data-action="accept" title="Mark as known risk so this finding doesn't affect your readiness score.">
                        Accept as known risk
                      </button>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endforeach; ?>
  </main>

  <!-- =========== SNAPSHOTS =========== -->
  <?php if ($snapshots !== []): ?>
    <section class="wp7rc-snapshots">
      <header class="wp7rc-snapshots__head">
        <h2>Plugin snapshots</h2>
        <p>Pre-update snapshots taken before plugin upgrades. Restore any plugin to its previous version with one click. Snapshots auto-prune after the <?php echo esc_html((string) WP7RC_SNAPSHOT_KEEP); ?> most recent.</p>
      </header>
      <ul class="wp7rc-snapshots__list">
        <?php foreach ($snapshots as $snap): ?>
          <li class="wp7rc-snapshot">
            <div class="wp7rc-snapshot__meta">
              <strong><?php echo esc_html((string) ($snap['plugin_dir_name'] ?? 'unknown')); ?></strong>
              <span class="wp7rc-snapshot__ver">v<?php echo esc_html((string) ($snap['version_at_snap'] ?? '?')); ?></span>
              <span class="wp7rc-snapshot__date"><?php echo esc_html((string) ($snap['created_at'] ?? '')); ?></span>
              <span class="wp7rc-snapshot__size"><?php echo esc_html(wp7rc_format_bytes((int) ($snap['byte_size'] ?? 0))); ?></span>
            </div>
            <div class="wp7rc-snapshot__actions">
              <button class="wp7rc-btn wp7rc-btn--ghost wp7rc-btn--sm" data-wp7rc-restore="<?php echo esc_attr((string) ($snap['id'] ?? '')); ?>">
                Restore this version
              </button>
              <span class="wp7rc-fix-status" role="status" aria-live="polite"></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <!-- =========== FOOTER / BRANDED CTA =========== -->
  <footer class="wp7rc-footer">
    <div class="wp7rc-footer__card">
      <h3>Need the full 80-point manual audit?</h3>
      <p>This plugin runs 30+ automated checks. The full printable enterprise readiness checklist covers 80 — including the human-judgment items (backup restore tested? rollback plan documented? AI Connectors policy signed off?) that no plugin can verify for you.</p>
      <a class="wp7rc-btn wp7rc-btn--primary" href="<?php echo esc_url(WP7RC_PRINTABLE_URL); ?>" target="_blank" rel="noopener">Open the printable 80-point checklist &rarr;</a>
    </div>

    <div class="wp7rc-footer__card wp7rc-footer__card--engagement">
      <h3>Want an engineer to run this for you?</h3>
      <p>Champlin Enterprises engineers staged, fully documented WordPress 7.0 upgrades for enterprise teams — audit, remediation, rollout, rollback, runbook your team owns next time. Three engagements per quarter, by application.</p>
      <a class="wp7rc-btn wp7rc-btn--ghost" href="<?php echo esc_url(WP7RC_ENGAGEMENT_URL); ?>" target="_blank" rel="noopener">Apply for an engagement &rarr;</a>
    </div>

    <div class="wp7rc-star-band">
      <span>Find this plugin useful?</span>
      <a class="wp7rc-star-link" href="<?php echo esc_url(WP7RC_GITHUB_URL); ?>" target="_blank" rel="noopener">
        <span class="wp7rc-star-icon" aria-hidden="true">★</span> Star us on GitHub
      </a>
      <span class="wp7rc-star-note">No telemetry, no signup &mdash; a star is the only way we hear from you.</span>
    </div>

    <p class="wp7rc-footer__meta">
      Audit generated <?php echo esc_html((string) ($results['generated_at'] ?? '')); ?> &middot;
      WP 7 Readiness Check v<?php echo esc_html(WP7RC_VERSION); ?> &middot;
      <a href="https://champlinenterprises.com?utm_source=plugin&amp;utm_medium=admin&amp;utm_campaign=wp7rc" target="_blank" rel="noopener">Champlin Enterprises</a> &middot;
      <a href="<?php echo esc_url(WP7RC_LANDING_URL); ?>" target="_blank" rel="noopener">Plugin homepage</a>
    </p>
  </footer>

</div>
