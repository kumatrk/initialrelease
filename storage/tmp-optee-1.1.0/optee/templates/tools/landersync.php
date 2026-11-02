<?php
/**
 * LanderSync — presentational page.
 *
 * No API, no credentials, nothing to configure. LanderSync is a separate free
 * Windows app; this page exists so people running campaigns in Optee know it's
 * there. Every link is external (forum guide, site, Store, GitHub).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$ls_forum  = 'https://afflift.com/f/threads/%F0%9F%96%A5%EF%B8%8F-landersync-publish-your-landing-pages-to-cloudflare-pages-in-one-click-free-open-source.16791/?r=571';
$ls_site   = 'https://www.landersync.com/';
$ls_store  = 'https://apps.microsoft.com/detail/9MXVFVCCB0C1';
$ls_github = 'https://github.com/landersync/landersync';
$ls_img    = OPTEE_PLUGIN_URL . 'assets/images/';
?>
<style>
#ls-page h1{font-size:22px;font-weight:800;letter-spacing:-.01em;color:#111827;margin:0}
#ls-page h2{font-size:16px;font-weight:700;color:#111827;margin:0 0 14px}
#ls-page .pill{display:inline-block;background:#eef7ff;color:#2aa5ff;font-weight:700;font-size:12px;padding:3px 10px;border-radius:999px;vertical-align:middle}
#ls-page .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
#ls-page .hero{display:flex;gap:28px;padding:26px 28px;align-items:flex-start;flex-wrap:wrap}
#ls-page .hero-txt{flex:1 1 320px;min-width:0}
#ls-page .hero-ico{width:56px;height:56px;border-radius:14px;display:block;margin-bottom:14px;box-shadow:0 4px 14px rgba(16,24,40,.14)}
#ls-page .hero-txt .tag{font-size:19px;font-weight:800;letter-spacing:-.01em;color:#111827;margin:0 0 8px}
#ls-page .hero-txt p{font-size:14px;color:#4b5563;line-height:1.6;margin:0 0 8px}
#ls-page .hero-shot{flex:1 1 380px;min-width:0}
#ls-page .hero-shot img{width:100%;height:auto;border-radius:12px;border:1px solid #e5e7eb;display:block}
#ls-page .btns{display:flex;gap:9px;flex-wrap:wrap;margin-top:18px}
#ls-page a.b{display:inline-flex;align-items:center;gap:7px;font-size:13.5px;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:10px;border:1px solid #e5e7eb;color:#374151;background:#fff;transition:.12s}
#ls-page a.b:hover{border-color:#d1d5db;background:#f9fafb}
#ls-page a.b.primary{background:#2aa5ff;border-color:#2aa5ff;color:#fff;box-shadow:0 4px 14px rgba(42,165,255,.28)}
#ls-page a.b.primary:hover{background:#1f8fe0}
#ls-page .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}
#ls-page .f{padding:18px 20px}
#ls-page .f .fi{width:38px;height:38px;border-radius:10px;background:#eff6ff;color:#2aa5ff;display:flex;align-items:center;justify-content:center;margin-bottom:12px}
#ls-page .f h3{font-size:14px;font-weight:700;color:#111827;margin:0 0 5px}
#ls-page .f p{font-size:12.5px;color:#6b7280;line-height:1.6;margin:0}
#ls-page .steps{counter-reset:s;display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}
#ls-page .step{padding:16px 18px;position:relative}
#ls-page .step .n{width:24px;height:24px;border-radius:999px;background:#111827;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;margin-bottom:10px}
#ls-page .step h3{font-size:13.5px;font-weight:700;color:#111827;margin:0 0 4px}
#ls-page .step p{font-size:12.5px;color:#6b7280;line-height:1.6;margin:0}
#ls-page .split{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}
#ls-page .note{padding:20px 22px}
#ls-page .note ul{margin:0;padding:0;list-style:none}
#ls-page .note li{font-size:12.5px;color:#4b5563;line-height:1.65;padding-left:20px;position:relative;margin-bottom:6px}
#ls-page .note li:before{content:"";position:absolute;left:4px;top:8px;width:5px;height:5px;border-radius:999px;background:#2aa5ff}
#ls-page .warn{background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:13px 15px;margin-top:14px}
#ls-page .warn p{font-size:12.5px;color:#92400e;line-height:1.6;margin:0}
#ls-page .cta{padding:26px 28px;text-align:center;background:linear-gradient(135deg,#f8fbff,#fff)}
#ls-page .cta h2{margin-bottom:8px}
#ls-page .cta p{font-size:13.5px;color:#4b5563;line-height:1.6;max-width:560px;margin:0 auto 18px}
#ls-page .foot{font-size:12px;color:#9ca3af;text-align:center;margin-top:4px}
</style>

<div id="ls-page" class="space-y-6">

    <div>
        <h1>LanderSync <span class="pill">Free · Open source · Windows</span></h1>
        <p class="mt-1 text-sm text-gray-500">Publish a folder of landing pages to Cloudflare Pages in one click, on your own free Cloudflare account.</p>
    </div>

    <!-- Hero -->
    <div class="card hero">
        <div class="hero-txt">
            <img class="hero-ico" src="<?php echo esc_url( $ls_img . 'landersync-icon.png' ); ?>" alt="LanderSync" width="56" height="56">
            <p class="tag">One folder. One button. One link.</p>
            <p>Hosting landers on Cloudflare Pages is free and takes unlimited traffic, but the workflow is the annoying part: zip the folder, open the dashboard, upload, wait — and do it again for every single edit.</p>
            <p>LanderSync is a small Windows app that does that part for you. Point it at the folder your landers are in, press Publish, and you get a live URL. No terminal, no zip files, nothing to sign up for.</p>
            <div class="btns">
                <a class="b primary" href="<?php echo esc_url( $ls_forum ); ?>" target="_blank" rel="noopener">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/></svg>
                    Read the guide on affLIFT
                </a>
                <a class="b" href="<?php echo esc_url( $ls_store ); ?>" target="_blank" rel="noopener">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Microsoft Store
                </a>
                <a class="b" href="<?php echo esc_url( $ls_site ); ?>" target="_blank" rel="noopener">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                    landersync.com
                </a>
                <a class="b" href="<?php echo esc_url( $ls_github ); ?>" target="_blank" rel="noopener">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.4 5.4 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4"/><path d="M9 18c-4.51 2-5-2-7-2"/></svg>
                    GitHub
                </a>
            </div>
        </div>
        <div class="hero-shot">
            <img src="<?php echo esc_url( $ls_img . 'landersync-app.png' ); ?>" alt="LanderSync — your sites, folder to publish, and live URLs" loading="lazy">
        </div>
    </div>

    <!-- What it does -->
    <div>
        <h2>What it does</h2>
        <div class="grid">
            <div class="card f">
                <div class="fi"><svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 13v8"/><path d="M4.393 15.269A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.436 8.284"/><path d="m8 17 4-4 4 4"/></svg></div>
                <h3>Publishes a whole folder</h3>
                <p>Everything in the folder becomes one site, and sub-folders become sub-pages. Ten landers go live in one press, not ten uploads.</p>
            </div>
            <div class="card f">
                <div class="fi"><svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9z"/></svg></div>
                <h3>Uploads only what changed</h3>
                <p>It compares against what's already online. Fix a typo in one headline and it re-uploads that one file, usually in about a second, instead of pushing the whole folder again.</p>
            </div>
            <div class="card f">
                <div class="fi"><svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7"/><circle cx="12" cy="12" r="3"/></svg></div>
                <h3>Tells you when a lander changed</h3>
                <p>It watches the folder and flags any page that no longer matches what's live — but it never publishes on its own. You don't want a half-finished page going live while traffic is running on it.</p>
            </div>
            <div class="card f">
                <div class="fi"><svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/><path d="M12 7v5l3 2"/></svg></div>
                <h3>Rolls back if you break something</h3>
                <p>Every publish is kept. Pick an earlier version and your previous pages are live again — no re-uploading, no digging through backups.</p>
            </div>
        </div>
    </div>

    <!-- How it works -->
    <div>
        <h2>How it works</h2>
        <div class="steps">
            <div class="card step">
                <div class="n">1</div>
                <h3>Connect Cloudflare</h3>
                <p>The app opens the right Cloudflare page with the correct permissions already ticked and finds your account ID by itself. Paste the token once.</p>
            </div>
            <div class="card step">
                <div class="n">2</div>
                <h3>Pick your folder</h3>
                <p>Browse to wherever your landers already live on your PC. Nothing gets moved, copied or restructured.</p>
            </div>
            <div class="card step">
                <div class="n">3</div>
                <h3>Press Publish</h3>
                <p>You get a live <code>.pages.dev</code> URL — ready to point a tracker or a campaign at.</p>
            </div>
            <div class="card step">
                <div class="n">4</div>
                <h3>Edit and publish again</h3>
                <p>Change a lander in whatever editor you use, come back, press Publish. Only the changed file goes up.</p>
            </div>
        </div>
    </div>

    <div class="split">
        <!-- Requirements -->
        <div class="card note">
            <h2>What you need</h2>
            <ul>
                <li>Windows 10 or 11</li>
                <li>A free Cloudflare account</li>
                <li>No Node, no npm, no command line — everything the app needs is inside it</li>
            </ul>
            <p style="font-size:12.5px;color:#6b7280;line-height:1.65;margin:12px 0 0">Cloudflare Pages itself is free and doesn't meter bandwidth, so the traffic you send to these landers doesn't cost you hosting.</p>
        </div>

        <!-- Token honesty -->
        <div class="card note">
            <h2>About the Cloudflare token</h2>
            <ul>
                <li>Encrypted on your own PC with Windows' built-in protection (DPAPI)</li>
                <li>Never written to a log, never sent anywhere except Cloudflare, on your behalf</li>
                <li>No analytics and no telemetry — the app talks to Cloudflare and nothing else</li>
                <li>MIT licensed, and the builds are made in public on GitHub Actions, so you can check what went into the version you download</li>
            </ul>
            <div class="warn">
                <p><strong>Worth knowing:</strong> Cloudflare can't scope a token to a single Pages project — a token that can publish can publish to every Pages project in that account. That's a Cloudflare limitation. If you're running client work, use a separate free Cloudflare account just for landers.</p>
            </div>
        </div>
    </div>

    <!-- CTA -->
    <div class="card cta">
        <h2>10 free landing pages come with it</h2>
        <p>The forum post has a .zip of 10 landing pages attached. Unzip it, point LanderSync at the folder, press Publish — all 10 go online at once, and you'll have your first live URL about a minute after installing the app.</p>
        <div class="btns" style="justify-content:center">
            <a class="b primary" href="<?php echo esc_url( $ls_forum ); ?>" target="_blank" rel="noopener">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/></svg>
                Get LanderSync + the free landers
            </a>
        </div>
        <p class="foot" style="margin-top:16px">LanderSync is a separate free app — there's nothing to connect or configure in Optee.</p>
    </div>

</div>
