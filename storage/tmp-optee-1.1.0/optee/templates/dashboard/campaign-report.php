<?php
/**
 * Standalone printable Campaign Report. Rendered as its own HTML document by
 * Optee::maybe_render_campaign_report() (no theme chrome), so it prints/saves to
 * PDF cleanly. Data comes from CampaignReport::build() — see that class for the
 * cache-only stats behaviour.
 */

use Optee\Plugin\Reports\CampaignReport;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$campaign_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
$tf          = isset( $_GET['timeframe'] ) ? sanitize_key( $_GET['timeframe'] ) : '7d';

// Pass the timeframe token straight through — the builder resolves it to the same
// date range (and cache keys) the stats views use.
$data = CampaignReport::build( $campaign_id, $tf );

// Inline the logo so the PDF never waits on / misses an external asset.
$logo_svg = @file_get_contents( OPTEE_PLUGIN_DIR . 'assets/images/optee-logo-light.svg' );

/** Small helpers for this template. */
$cls = static function ( float $v ): string { return $v >= 0 ? 'pos' : 'neg'; };
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo is_wp_error( $data ) ? 'Campaign Report' : esc_html( $data['campaign']['title'] . ' — Campaign Report' ); ?></title>
<style>
  :root{--ink:#111827;--muted:#6b7280;--faint:#9ca3af;--line:#e5e7eb;--line-soft:#f3f4f6;--paper:#fff;--cool:#f8fafc;--brand:#2aa5ff;--indigo:#4f46e5;--good:#16a34a;--good-bg:#dcfce7;--bad:#dc2626;--bad-bg:#fee2e2;--warn:#d97706;--warn-bg:#fef3c7;--auto-bg:#ede9fe;--auto-fg:#6d28d9;--man-bg:#dbeafe;--man-fg:#1d4ed8;}
  *{box-sizing:border-box;}
  html{background:#e9edf2;}
  body{margin:0;color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.5;font-size:14px;-webkit-font-smoothing:antialiased;}
  .page{max-width:840px;margin:24px auto 60px;background:var(--paper);padding:40px 44px 52px;box-shadow:0 12px 40px rgba(0,0,0,.10);border-radius:6px;}
  .tnum{font-variant-numeric:tabular-nums;}
  .mono{font-family:ui-monospace,"SF Mono",Menlo,monospace;}
  .pos{color:var(--good);}.neg{color:var(--bad);}

  .toolbar{position:sticky;top:0;z-index:10;max-width:840px;margin:0 auto;padding:10px 4px;display:flex;justify-content:flex-end;gap:8px;}
  .btn{font:inherit;font-size:13px;font-weight:600;border-radius:8px;padding:8px 16px;cursor:pointer;border:1px solid var(--line);background:#fff;color:var(--ink);}
  .btn.primary{background:var(--brand);border-color:var(--brand);color:#fff;}

  .rpt-head{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;}
  .brand{display:flex;align-items:center;gap:12px;}
  .brand svg,.brand img{height:30px;width:auto;display:block;}
  .brand-report{font-size:16px;font-weight:600;color:var(--muted);padding-left:12px;border-left:1px solid var(--line);}
  h1{font-size:22px;margin:16px 0 2px;letter-spacing:-.02em;}
  h1 .cid{color:var(--faint);font-weight:500;font-size:16px;}
  .sub{color:var(--muted);font-size:13px;margin:0;}
  .gen{text-align:right;font-size:11.5px;color:var(--faint);}
  .gen .tf{display:inline-block;margin-top:4px;padding:2px 9px;border-radius:9999px;background:var(--cool);border:1px solid var(--line);color:var(--muted);font-weight:600;}

  .meta-grid{margin-top:18px;display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--line);border:1px solid var(--line);border-radius:8px;overflow:hidden;}
  .meta-grid>div{background:var(--paper);padding:10px 12px;}
  .meta-grid dt{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);margin:0 0 2px;}
  .meta-grid dd{margin:0;font-size:13px;font-weight:600;}

  section{margin-top:34px;}
  .sec-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink);margin:0 0 14px;}
  .sec-title span{color:var(--faint);font-weight:600;}
  .sec-cap{font-size:12px;color:var(--muted);margin:-8px 0 14px;}
  .note-box{border:1px dashed var(--line);border-radius:8px;padding:12px 14px;font-size:12.5px;color:var(--muted);background:var(--cool);}

  .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
  .kpi{border:1px solid var(--line);border-radius:10px;padding:12px 14px;}
  .kpi .k{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);}
  .kpi .v{font-size:21px;font-weight:700;letter-spacing:-.01em;margin-top:3px;}
  .kpi .d{font-size:11.5px;margin-top:2px;color:var(--muted);}
  .counts{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:12px;}
  .count{border:1px solid var(--line);border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;}
  .count .n{font-size:20px;font-weight:700;}
  .count .l{font-size:12px;color:var(--muted);line-height:1.25;}

  .cmp{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
  .cmp .c{border:1px solid var(--line);border-radius:10px;padding:12px 14px;}
  .cmp .k{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);}
  .cmp .now{font-size:18px;font-weight:700;letter-spacing:-.01em;margin-top:3px;font-variant-numeric:tabular-nums;}
  .cmp .prev{font-size:11.5px;color:var(--muted);margin-top:2px;font-variant-numeric:tabular-nums;}
  .delta{display:inline-block;font-size:11px;font-weight:700;padding:3px 8px;border-radius:7px;margin-top:7px;line-height:1.45;}
  .delta.good{background:var(--good-bg);color:var(--good);}
  .delta.bad{background:var(--bad-bg);color:var(--bad);}

  .chart-wrap{border:1px solid var(--line);border-radius:10px;padding:16px 16px 10px;}
  .legend{display:flex;gap:16px;font-size:12px;color:var(--muted);margin-bottom:12px;align-items:center;}
  .legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px;}
  .legend .linekey{display:inline-block;width:16px;height:0;border-top:2px solid var(--indigo);margin-right:5px;vertical-align:4px;position:relative;}
  .legend .linekey::after{content:"";position:absolute;left:5px;top:-3px;width:5px;height:5px;border-radius:50%;background:var(--indigo);}
  .chart-area{display:flex;gap:8px;}
  .yaxis{position:relative;flex:0 0 40px;height:150px;}
  .yaxis span{position:absolute;right:0;transform:translateY(-50%);font-size:10px;color:var(--indigo);font-variant-numeric:tabular-nums;font-weight:600;white-space:nowrap;}
  .yaxis .cap{top:-12px;transform:none;color:var(--faint);font-weight:700;font-size:9px;letter-spacing:.04em;}
  .plot{position:relative;flex:1 1 auto;min-width:0;}
  .chart{display:flex;align-items:flex-end;height:150px;padding:0 2px;}
  .col{flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;height:100%;justify-content:flex-end;}
  .bars{display:flex;align-items:flex-end;justify-content:center;height:128px;width:100%;}
  .bar{flex:1 1 0;min-width:1px;border-radius:2px 2px 0 0;}
  .bar.c{background:#fca5a5;}
  .bar.r{background:#86efac;}
  .roi-svg{position:absolute;left:0;top:0;width:100%;height:150px;pointer-events:none;overflow:visible;}
  .xaxis{display:flex;padding:8px 2px 0;border-top:1px solid var(--line-soft);margin-top:6px;}
  .xcol{flex:1 1 0;min-width:0;text-align:center;}
  .xcol .day{font-size:10px;color:var(--muted);white-space:nowrap;}
  .xcol .roi{font-size:11px;font-weight:700;margin-top:2px;}

  table{width:100%;border-collapse:collapse;font-size:12.5px;}
  th,td{text-align:right;padding:7px 10px;border-bottom:1px solid var(--line-soft);}
  th:first-child,td:first-child{text-align:left;}
  thead th{font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);border-bottom:1px solid var(--line);}
  tbody tr:last-child td{border-bottom:none;}
  tfoot td{font-weight:700;border-top:1px solid var(--line);}
  .tbl-wrap{border:1px solid var(--line);border-radius:10px;overflow:hidden;}
  .tbl-scroll{overflow-x:auto;}
  .tbl-wrap th,.tbl-wrap td{padding-left:14px;padding-right:14px;}
  tr.row-pos{background:#f0fdf4;}
  tr.row-neg{background:#fef2f2;}
  .chip{display:inline-block;font-size:10.5px;font-weight:600;padding:1px 7px;border-radius:9999px;vertical-align:1px;white-space:nowrap;}
  .chip.ok{background:var(--good-bg);color:var(--good);}
  .chip.no{background:var(--bad-bg);color:var(--bad);}

  .rules-list{display:flex;flex-direction:column;gap:10px;}
  .rule-card{border:1px solid var(--line);border-radius:10px;padding:13px 15px;}
  .rule-top{display:flex;align-items:center;gap:8px;}
  .rule-name{font-size:14px;font-weight:700;}
  .chip.active{background:var(--good-bg);color:var(--good);}
  .chip.off{background:var(--line-soft);color:var(--muted);}
  .rule-logic{margin-top:8px;font-size:12.5px;display:flex;flex-wrap:wrap;align-items:center;gap:6px;}
  .lg{font-size:10px;font-weight:700;letter-spacing:.05em;padding:1px 7px;border-radius:5px;}
  .lg.if{background:#eef2ff;color:var(--indigo);}
  .lg.then{background:#ecfdf5;color:var(--good);}
  .cond{background:var(--cool);border:1px solid var(--line);border-radius:6px;padding:2px 8px;font-weight:600;}
  .op{color:var(--faint);font-weight:700;font-size:11px;}
  .scope{color:var(--faint);}
  .act{font-weight:600;}
  .rule-foot{margin-top:9px;font-size:11.5px;color:var(--muted);border-top:1px solid var(--line-soft);padding-top:8px;}

  .timeline{position:relative;margin-left:6px;padding-left:26px;}
  .timeline::before{content:"";position:absolute;left:5px;top:6px;bottom:6px;width:2px;background:var(--line);}
  .ev{position:relative;margin-bottom:16px;}
  .ev::before{content:"";position:absolute;left:-26px;top:4px;width:12px;height:12px;border-radius:50%;background:#fff;border:2px solid var(--faint);}
  .ev.auto::before{border-color:var(--auto-fg);}
  .ev.man::before{border-color:var(--man-fg);}
  .ev.noop::before{border-color:var(--line);background:var(--line-soft);}
  .ev-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
  .ev-time{font-size:11.5px;color:var(--faint);font-variant-numeric:tabular-nums;}
  .badge{font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:2px 8px;border-radius:9999px;}
  .badge.auto{background:var(--auto-bg);color:var(--auto-fg);}
  .badge.man{background:var(--man-bg);color:var(--man-fg);}
  .badge.noop{background:var(--line-soft);color:var(--muted);}
  .ev-title{font-size:13.5px;font-weight:600;margin:5px 0 0;}
  .ev-title .rule{color:var(--auto-fg);}
  .ev-body{margin-top:8px;border:1px solid var(--line);border-radius:8px;overflow:hidden;}
  .zrow{padding:9px 12px;border-bottom:1px solid var(--line-soft);font-size:12.5px;}
  .zrow:last-child{border-bottom:none;}
  .zrow .zid{font-weight:700;}
  .zrow .why{color:var(--muted);margin-top:2px;font-size:12px;}
  .zrow .why b{color:var(--ink);font-weight:600;}
  .ev-note{font-size:12px;color:var(--muted);margin:6px 0 0;padding-left:2px;}
  .empty{font-size:13px;color:var(--muted);padding:8px 0;}

  .savings{display:flex;align-items:center;gap:20px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:12px;padding:18px 22px;}
  .savings .big{font-size:30px;font-weight:800;color:#15803d;letter-spacing:-.02em;line-height:1;font-variant-numeric:tabular-nums;}
  .savings .big small{display:block;font-size:11px;font-weight:600;color:#16a34a;letter-spacing:.03em;text-transform:uppercase;margin-top:6px;}
  .savings .txt{font-size:13px;color:#166534;line-height:1.5;}
  .savings .txt b{color:#14532d;}
  .savings .txt .calc{color:#4d7c5a;font-size:11.5px;margin-top:4px;display:block;}

  .footnote{margin-top:40px;padding-top:14px;border-top:1px solid var(--line);font-size:11px;color:var(--faint);}

  /* On-screen phones: tighten the paper, stop the header column from squeezing the
     "Last N days" pill into one-word-per-line, and reflow the wide metric grids. */
  @media (max-width:640px){
    html{background:#f3f4f6;}
    .toolbar{padding:10px 12px;}
    .page{margin:10px auto 32px;padding:22px 16px 34px;border-radius:0;}
    .rpt-head{flex-direction:column;gap:10px;}
    .gen{text-align:left;}
    .gen .tf{white-space:nowrap;}
    h1{font-size:19px;}
    h1 .cid{font-size:14px;}
    .kpis{grid-template-columns:1fr 1fr;}
    .cmp{grid-template-columns:1fr 1fr;}
    .counts{grid-template-columns:1fr 1fr;}
    .savings{flex-direction:column;align-items:flex-start;gap:12px;}
  }

  @media print{
    html,body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .toolbar{display:none!important;}
    .page{box-shadow:none;margin:0;max-width:none;padding:0;border-radius:0;}
    section{break-inside:auto;margin-top:22px;}
    section:first-of-type{margin-top:0;}
    .kpi,.count,.cmp .c,.rule-card,.ev,.chart-wrap,.meta-grid,.tbl-wrap,.savings{break-inside:avoid;}
    .sec-title{break-after:avoid;}
    thead{display:table-header-group;}
    tr{break-inside:avoid;}
    @page{size:A4 portrait;margin:14mm 12mm;}
  }
</style>
</head>
<body>
<?php if ( is_wp_error( $data ) ) : ?>
  <div class="page"><h1>Campaign report unavailable</h1><p class="sub"><?php echo esc_html( $data->get_error_message() ); ?></p></div>
<?php else :
    $c = $data['campaign'];
?>
<div class="toolbar">
  <button class="btn primary" onclick="window.print()">Save as PDF</button>
  <button class="btn" onclick="window.close()">Close</button>
</div>

<div class="page">

  <div class="rpt-head">
    <div>
      <div class="brand">
        <?php if ( $logo_svg ) { echo $logo_svg; } else { echo '<strong style="font-size:16px;">Optee</strong>'; } ?>
        <span class="brand-report">Campaign Report</span>
      </div>
      <h1><?php echo esc_html( $c['title'] ); ?> &nbsp;<span class="cid">#<?php echo (int) $c['id']; ?></span></h1>
      <p class="sub">Campaign optimization report</p>
    </div>
    <div class="gen">
      <div><?php echo esc_html( $data['range']['label'] ); ?></div>
      <div class="tf">Last <?php echo (int) $data['range']['days']; ?> days</div>
    </div>
  </div>

  <dl class="meta-grid">
    <div><dt>Traffic source</dt><dd><?php echo esc_html( $c['traffic_source'] ); ?></dd></div>
    <div><dt>Tracker</dt><dd><?php echo esc_html( $c['tracker'] ); ?></dd></div>
    <div><dt>Status</dt><dd style="color:<?php echo $c['status'] === 'Active' ? 'var(--good)' : 'var(--warn)'; ?>"><?php echo esc_html( $c['status'] ); ?></dd></div>
  </dl>

  <!-- SUMMARY -->
  <section>
    <div class="sec-title">Summary</div>
    <?php if ( $data['summary'] ) { $s = $data['summary']; ?>
    <div class="kpis">
      <div class="kpi"><div class="k">Cost</div><div class="v tnum"><?php echo esc_html( CampaignReport::money( $s['cost'] ) ); ?></div><div class="d"><?php echo (int) $data['range']['days']; ?>-day spend</div></div>
      <div class="kpi"><div class="k">Revenue</div><div class="v tnum"><?php echo esc_html( CampaignReport::money( $s['revenue'] ) ); ?></div><div class="d"><?php echo (int) $s['conversions']; ?> conversions</div></div>
      <div class="kpi"><div class="k">Profit</div><div class="v tnum <?php echo $cls( $s['profit'] ); ?>"><?php echo esc_html( CampaignReport::signed_money( $s['profit'] ) ); ?></div><div class="d">net</div></div>
      <div class="kpi"><div class="k">ROI</div><div class="v tnum <?php echo $cls( $s['roi'] ); ?>"><?php echo esc_html( CampaignReport::pct( $s['roi'] ) ); ?></div><div class="d">period average</div></div>
    </div>
    <?php } else { ?>
    <div class="note-box">Performance stats aren't cached yet for this campaign. Open the campaign's <b>Days</b> view once, then regenerate — the timeline, rules and manual actions below are always complete.</div>
    <?php } ?>
    <div class="counts">
      <div class="count"><div class="n" style="color:var(--auto-fg)"><?php echo (int) $data['counts']['auto_blocks']; ?></div><div class="l">zones blocked<br>by rules (auto)</div></div>
      <div class="count"><div class="n" style="color:var(--man-fg)"><?php echo (int) $data['counts']['manual_actions']; ?></div><div class="l">manual zone<br>actions (you)</div></div>
      <div class="count"><div class="n"><?php echo (int) $data['counts']['rule_runs']; ?></div><div class="l">rule runs<br>this period</div></div>
    </div>
  </section>

  <!-- PREVIOUS-PERIOD COMPARISON -->
  <?php if ( $data['summary'] && $data['prev'] ) { $s = $data['summary']; $p = $data['prev'];
      $d_cost = $s['cost'] - $p['cost'];
      $d_rev  = $s['revenue'] - $p['revenue'];
      $d_prof = $s['profit'] - $p['profit'];
      $d_roi  = $s['roi'] - $p['roi'];
  ?>
  <section>
    <div class="sec-title">Compared to previous <?php echo (int) $data['range']['days']; ?> days</div>
    <div class="cmp">
      <div class="c"><div class="k">Cost</div><div class="now"><?php echo esc_html( CampaignReport::money( $s['cost'] ) ); ?></div><div class="prev">was <?php echo esc_html( CampaignReport::money( $p['cost'] ) ); ?></div>
        <div class="delta <?php echo $d_cost <= 0 ? 'good' : 'bad'; ?>"><?php echo $d_cost <= 0 ? '↓' : '↑'; ?> <?php echo esc_html( CampaignReport::money( abs( $d_cost ) ) ); ?> spend</div></div>
      <div class="c"><div class="k">Revenue</div><div class="now"><?php echo esc_html( CampaignReport::money( $s['revenue'] ) ); ?></div><div class="prev">was <?php echo esc_html( CampaignReport::money( $p['revenue'] ) ); ?></div>
        <div class="delta <?php echo $d_rev >= 0 ? 'good' : 'bad'; ?>"><?php echo $d_rev >= 0 ? '↑' : '↓'; ?> <?php echo esc_html( CampaignReport::money( abs( $d_rev ) ) ); ?></div></div>
      <div class="c"><div class="k">Profit</div><div class="now <?php echo $cls( $s['profit'] ); ?>"><?php echo esc_html( CampaignReport::signed_money( $s['profit'] ) ); ?></div><div class="prev">was <?php echo esc_html( CampaignReport::signed_money( $p['profit'] ) ); ?></div>
        <div class="delta <?php echo $d_prof >= 0 ? 'good' : 'bad'; ?>"><?php echo $d_prof >= 0 ? '↑' : '↓'; ?> <?php echo esc_html( CampaignReport::money( abs( $d_prof ) ) ); ?><?php echo ( $p['profit'] < 0 && $s['profit'] >= 0 ) ? ' · turned profitable' : ''; ?></div></div>
      <div class="c"><div class="k">ROI</div><div class="now <?php echo $cls( $s['roi'] ); ?>"><?php echo esc_html( CampaignReport::pct( $s['roi'] ) ); ?></div><div class="prev">was <?php echo esc_html( CampaignReport::pct( $p['roi'] ) ); ?></div>
        <div class="delta <?php echo $d_roi >= 0 ? 'good' : 'bad'; ?>"><?php echo $d_roi >= 0 ? '↑' : '↓'; ?> <?php echo esc_html( number_format( abs( $d_roi ), 1 ) ); ?> pts</div></div>
    </div>
  </section>
  <?php } ?>

  <!-- PER-DAY PERFORMANCE -->
  <?php if ( ! empty( $data['perday'] ) ) {
      $pd   = $data['perday'];
      $n    = count( $pd );
      $maxv = 0.0;
      foreach ( $pd as $row ) { $maxv = max( $maxv, $row['cost'], $row['revenue'] ); }
      $maxv = $maxv > 0 ? $maxv : 1;
      $rois = array_map( static fn( $r ) => $r['roi'], $pd );
      $rmax = max( 25, (int) ( ceil( ( max( $rois ) ?: 0 ) / 25 ) * 25 ) );
      $rmin = min( -25, (int) ( floor( ( min( $rois ) ?: 0 ) / 25 ) * 25 ) );
      $span = ( $rmax - $rmin ) ?: 1;
      $roiY = static fn( $roi ) => 10 + ( $rmax - $roi ) / $span * 130;
      // Build ticks (rmax, mid, 0, rmin) deduped.
      $ticks = array_values( array_unique( [ $rmax, (int) ( round( $rmax / 2 / 25 ) * 25 ), 0, $rmin ] ) );
      rsort( $ticks );
      // ROI polyline points.
      $pts = [];
      foreach ( $pd as $i => $row ) {
          $x = ( $i + 0.5 ) / $n * 700;
          $pts[] = round( $x, 1 ) . ',' . round( $roiY( $row['roi'] ), 1 );
      }
      // Responsive sizing so long ranges (e.g. 30/60/90d) still fit the card.
      $chart_gap  = $n > 24 ? 2 : ( $n > 14 ? 3 : ( $n > 7 ? 6 : 10 ) );
      $bar_gap    = $n > 14 ? 1 : ( $n > 7 ? 2 : 4 );
      $bar_max    = $n > 24 ? 6 : ( $n > 14 ? 8 : ( $n > 7 ? 12 : 15 ) );
      $dot_r      = $n > 20 ? 2 : ( $n > 10 ? 2.5 : 3.5 );
      $label_step = max( 1, (int) ceil( $n / 12 ) );   // show at most ~12 date labels
      $show_day_roi = ( $n <= 12 );                    // per-column ROI% only when it fits
  ?>
  <section>
    <div class="sec-title">Performance per day <span>· cost vs revenue, ROI trend</span></div>
    <div class="chart-wrap">
      <div class="legend"><span><i style="background:#fca5a5"></i>Cost</span><span><i style="background:#86efac"></i>Revenue</span><span><span class="linekey"></span>ROI</span></div>
      <div class="chart-area">
        <div class="yaxis">
          <span class="cap">ROI</span>
          <?php foreach ( $ticks as $t ) : ?>
            <span style="top:<?php echo round( $roiY( $t ), 1 ); ?>px"><?php echo ( $t > 0 ? '+' : '' ) . (int) $t; ?>%</span>
          <?php endforeach; ?>
        </div>
        <div class="plot">
          <div class="chart" style="gap:<?php echo $chart_gap; ?>px">
            <?php foreach ( $pd as $row ) :
              $ch = round( $row['cost'] / $maxv * 128 );
              $rh = round( $row['revenue'] / $maxv * 128 );
            ?>
            <div class="col"><div class="bars" style="gap:<?php echo $bar_gap; ?>px"><div class="bar c" style="height:<?php echo $ch; ?>px;max-width:<?php echo $bar_max; ?>px"></div><div class="bar r" style="height:<?php echo $rh; ?>px;max-width:<?php echo $bar_max; ?>px"></div></div></div>
            <?php endforeach; ?>
          </div>
          <svg class="roi-svg" viewBox="0 0 700 150" preserveAspectRatio="none" aria-hidden="true">
            <?php foreach ( $ticks as $t ) : $yy = round( $roiY( $t ), 1 ); ?>
              <line x1="0" y1="<?php echo $yy; ?>" x2="700" y2="<?php echo $yy; ?>" stroke="<?php echo $t === 0 ? '#d1d5db' : '#f3f4f6'; ?>"<?php echo $t === 0 ? ' stroke-dasharray="4 4"' : ''; ?> stroke-width="1"/>
            <?php endforeach; ?>
            <polyline points="<?php echo esc_attr( implode( ' ', $pts ) ); ?>" fill="none" stroke="#4f46e5" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/>
            <g fill="#4f46e5"><?php foreach ( $pd as $i => $row ) { $x = round( ( $i + 0.5 ) / $n * 700, 1 ); $y = round( $roiY( $row['roi'] ), 1 ); echo '<circle cx="' . $x . '" cy="' . $y . '" r="' . $dot_r . '"/>'; } ?></g>
          </svg>
          <div class="xaxis" style="gap:<?php echo $chart_gap; ?>px">
            <?php foreach ( $pd as $i => $row ) :
              $show_label = ( $i % $label_step === 0 ) || ( $i === $n - 1 );
            ?>
              <div class="xcol"><div class="day"><?php echo $show_label ? esc_html( $row['label'] ) : ''; ?></div><?php if ( $show_day_roi ) : ?><div class="roi <?php echo $cls( $row['roi'] ); ?>"><?php echo esc_html( CampaignReport::pct( $row['roi'] ) ); ?></div><?php endif; ?></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="tbl-wrap" style="margin-top:12px;"><div class="tbl-scroll">
      <table>
        <thead><tr><th>Day</th><th>Impr.</th><th>Clicks</th><th>Cost</th><th>Conv.</th><th>Revenue</th><th>Profit</th><th>ROI</th></tr></thead>
        <tbody class="tnum">
          <?php foreach ( array_reverse( $pd ) as $row ) : // table newest-first (chart stays oldest→newest) ?>
          <tr class="<?php echo $row['roi'] >= 0 ? 'row-pos' : 'row-neg'; ?>">
            <td><?php echo esc_html( $row['label'] ); ?></td>
            <td><?php echo number_format( $row['impr'] ); ?></td>
            <td><?php echo number_format( $row['clicks'] ); ?></td>
            <td><?php echo esc_html( CampaignReport::money( $row['cost'] ) ); ?></td>
            <td><?php echo (int) $row['conv']; ?></td>
            <td><?php echo esc_html( CampaignReport::money( $row['revenue'] ) ); ?></td>
            <td class="<?php echo $cls( $row['profit'] ); ?>"><?php echo esc_html( CampaignReport::signed_money( $row['profit'] ) ); ?></td>
            <td class="<?php echo $cls( $row['roi'] ); ?>"><?php echo esc_html( CampaignReport::pct( $row['roi'] ) ); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </section>
  <?php } ?>

  <!-- RULES ATTACHED -->
  <section>
    <div class="sec-title">Rules attached to this campaign</div>
    <?php if ( empty( $data['rules'] ) ) : ?>
      <div class="empty">No automation rules are attached to this campaign.</div>
    <?php else : ?>
    <div class="rules-list">
      <?php foreach ( $data['rules'] as $r ) : ?>
      <div class="rule-card">
        <div class="rule-top"><span class="rule-name"><?php echo esc_html( $r['title'] ); ?></span>
          <span class="chip <?php echo $r['enabled'] ? 'active' : 'off'; ?>"><?php echo $r['enabled'] ? 'Active' : 'Disabled'; ?></span></div>
        <div class="rule-logic">
          <span class="lg if">IF</span>
          <?php if ( empty( $r['conditions'] ) ) : ?>
            <span class="scope">no conditions</span>
          <?php else : foreach ( $r['conditions'] as $ci => $cond ) : ?>
            <?php if ( $ci > 0 ) : ?><span class="op">AND</span><?php endif; ?>
            <span class="cond"><?php echo esc_html( $cond['metric'] . ' ' . $cond['op'] . ' ' . $cond['value'] ); ?></span>
          <?php endforeach; endif; ?>
          <span class="scope">· <?php echo esc_html( $r['timeframe'] ); ?></span>
          <span class="lg then" style="margin-left:6px;">THEN</span>
          <span class="act"><?php echo $r['action_emoji']; ?> <?php echo esc_html( $r['action'] ); ?></span>
        </div>
        <div class="rule-foot"><?php echo $r['interval'] ? esc_html( ucfirst( $r['interval'] ) ) . ' · ' : ''; ?><?php echo (int) $r['runs']; ?> run<?php echo $r['runs'] === 1 ? '' : 's'; ?> this period<?php echo $r['blocked'] > 0 ? ' · <b>' . (int) $r['blocked'] . ' zones blocked</b>' : ''; ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <!-- OPTIMIZATION TIMELINE -->
  <section>
    <div class="sec-title">Optimization timeline <span>· what changed, when, and why</span></div>
    <?php if ( empty( $data['timeline'] ) ) : ?>
      <div class="empty">No rule or manual changes recorded in this period.</div>
    <?php else : ?>
    <div class="timeline">
      <?php foreach ( $data['timeline'] as $ev ) : ?>
      <div class="ev <?php echo esc_attr( $ev['group'] ); ?>">
        <div class="ev-head"><span class="ev-time"><?php echo esc_html( $ev['ts_label'] ); ?></span><span class="badge <?php echo esc_attr( $ev['group'] ); ?>"><?php echo esc_html( $ev['badge'] ); ?></span></div>
        <p class="ev-title"<?php echo $ev['group'] === 'noop' ? ' style="color:var(--muted);font-weight:500;"' : ''; ?>><?php echo $ev['emoji']; ?> <?php echo $ev['title']; /* built with esc_html inside */ ?></p>
        <?php if ( ! empty( $ev['zones'] ) ) : ?>
        <div class="ev-body">
          <?php foreach ( $ev['zones'] as $z ) : ?>
          <div class="zrow"><span class="zid mono"><?php echo esc_html( $z['zid'] ); ?></span>
            <?php if ( ! empty( $z['why'] ) ) : ?><div class="why"><?php echo $z['why']; /* pre-escaped */ ?></div><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ( ! empty( $ev['note'] ) ) : ?><p class="ev-note"><?php echo esc_html( $ev['note'] ); ?></p><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <!-- ZONES TOUCHED -->
  <?php if ( ! empty( $data['zones'] ) ) :
      $zone_has_perf = false;
      foreach ( $data['zones'] as $z ) { if ( $z['profit'] !== null ) { $zone_has_perf = true; break; } }
  ?>
  <section>
    <div class="sec-title">Zones changed this period</div>
    <p class="sec-cap"><?php echo $zone_has_perf
        ? 'Zone performance over the period — usually a loss, which is why it was blocked.'
        : 'Per-zone spend isn\'t cached for this timeframe yet — open the campaign\'s Days ▸ Zones view for this range to fill it in. Which zones changed, and when, is still accurate below.'; ?></p>
    <div class="tbl-wrap"><div class="tbl-scroll">
      <table>
        <thead><tr><th>Zone</th><th>Changed by</th><th>When</th><th>Spend</th><th>Conv.</th><th>Revenue</th><th>Profit</th><th>ROI</th><th>Current</th></tr></thead>
        <tbody class="tnum">
          <?php foreach ( $data['zones'] as $z ) :
            $by_color = $z['changed_class'] === 'auto' ? 'var(--auto-fg)' : ( $z['changed_class'] === 'man' ? 'var(--man-fg)' : 'var(--ink)' );
            $has = $z['profit'] !== null;
          ?>
          <tr>
            <td class="mono"><?php echo esc_html( $z['zid'] ); ?></td>
            <td style="text-align:right;color:<?php echo $by_color; ?>"><?php echo esc_html( $z['changed_by'] ); ?></td>
            <td><?php echo esc_html( $z['when'] ); ?></td>
            <td><?php echo $has ? esc_html( CampaignReport::money( $z['cost'] ) ) : '—'; ?></td>
            <td><?php echo $has ? (int) $z['conv'] : '—'; ?></td>
            <td><?php echo $has ? esc_html( CampaignReport::money( $z['revenue'] ) ) : '—'; ?></td>
            <td class="<?php echo $has ? $cls( $z['profit'] ) : ''; ?>"><?php echo $has ? esc_html( CampaignReport::signed_money( $z['profit'] ) ) : '—'; ?></td>
            <td class="<?php echo $has ? $cls( $z['roi'] ) : ''; ?>"><?php echo $has ? esc_html( CampaignReport::pct( $z['roi'] ) ) : '—'; ?></td>
            <td style="text-align:right"><span class="chip <?php echo $z['blocked'] ? 'no' : 'ok'; ?>"><?php echo $z['blocked'] ? 'Blocked' : 'Active'; ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </section>
  <?php endif; ?>

  <!-- ESTIMATED SAVINGS -->
  <?php if ( ! empty( $data['savings'] ) ) { $sv = $data['savings']; ?>
  <section>
    <div class="sec-title">Estimated savings from blocks</div>
    <div class="savings">
      <div class="big"><?php echo esc_html( CampaignReport::money( $sv['removed'] ) ); ?><small>losing spend removed · <?php echo (int) $sv['days']; ?>d</small></div>
      <div class="txt">
        <?php echo (int) $sv['zone_count']; ?> zone<?php echo $sv['zone_count'] === 1 ? ' was' : 's were'; ?> cut while running at a loss (avg <b><?php echo esc_html( CampaignReport::signed_money( -$sv['avg'] ) ); ?></b> each). Blocking them stops that bleed.
        <span class="calc">≈ <b><?php echo esc_html( CampaignReport::money( $sv['monthly_est'] ) ); ?> / month</b> saved at this rate. Estimate assumes blocked zones would have sustained the same daily loss.</span>
      </div>
    </div>
  </section>
  <?php } ?>

  <div class="footnote">
    Optee campaign report · <?php echo esc_html( $c['title'] ); ?> (#<?php echo (int) $c['id']; ?>) · <?php echo esc_html( $data['range']['label'] ); ?> · Generated <?php echo esc_html( $data['generated'] ); ?> ·
    Figures from the campaign's tracker and Optee automation logs.
  </div>

</div>
<?php endif; ?>
</body>
</html>
