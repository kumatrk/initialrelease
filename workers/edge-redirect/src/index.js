/**
 * Simple Kuma Edge Redirect Engine (Cloudflare Worker)
 * Reads campaign snapshots from KV, redirects immediately, posts analytics via waitUntil().
 */

export default {
  async fetch(request, env, ctx) {
    try {
      return await handleRequest(request, env, ctx);
    } catch (err) {
      console.error('edge-redirect error', err);
      return fallbackToOrigin(request, env);
    }
  },
};

async function handleRequest(request, env, ctx) {
  const url = new URL(request.url);

  if (!isClickPath(url)) {
    return fallbackToOrigin(request, env);
  }

  const pathInfo = extractPathInfo(url);
  if (!pathInfo.key && !pathInfo.slug) {
    return new Response('Campaign key missing', { status: 400 });
  }

  const campaign = await loadCampaign(env.CAMPAIGNS, pathInfo);
  if (!campaign) {
    return fallbackToOrigin(request, env);
  }

  // Prefer slug-key payload slug_id; otherwise resolve from path + campaign.slugs
  if (!campaign.slug_id && pathInfo.slug && Array.isArray(campaign.slugs)) {
    const match = campaign.slugs.find((s) => s && s.slug === pathInfo.slug);
    if (match) campaign.slug_id = match.id;
  }

  if (campaign.status !== 'active' || !campaign.edge_enabled) {
    return fallbackToOrigin(request, env);
  }

  const params = Object.fromEntries(url.searchParams.entries());
  delete params.k;

  const enrichment = enrichRequest(request);
  const clickId = crypto.randomUUID();

  // campaign_click rules first
  const ruleUrl = matchRedirectRule(campaign, params, enrichment, 'campaign_click');
  if (ruleUrl) {
    const finalUrl = appendAllowedParams(ruleUrl, clickId, params, campaign, false);
    ctx.waitUntil(
      postClick(env, buildPayload(campaign, clickId, params, enrichment, {
        offer_id: null,
        landing_page_id: null,
        is_direct_to_offer: false,
        redirect_rule_matched: true,
        destination_url: finalUrl,
        cost: captureCost(campaign, params),
        slug_id: campaign.slug_id || null,
      }))
    );
    return redirect(finalUrl);
  }

  const destination = resolveDestination(campaign);
  if (!destination || !destination.url) {
    if (campaign.fallback_offer && campaign.fallback_offer.url) {
      const finalUrl = buildDestinationUrl(campaign.fallback_offer.url, clickId, params, campaign, true);
      ctx.waitUntil(
        postClick(env, buildPayload(campaign, clickId, params, enrichment, {
          offer_id: campaign.fallback_offer.id,
          landing_page_id: null,
          is_direct_to_offer: true,
          redirect_rule_matched: false,
          destination_url: finalUrl,
          cost: captureCost(campaign, params),
          slug_id: campaign.slug_id || null,
        }))
      );
      return redirect(finalUrl);
    }
    return fallbackToOrigin(request, env);
  }

  let destUrl = destination.url;
  const isDto = destination.type === 'offer';

  if (isDto) {
    const offerRule = matchRedirectRule(campaign, params, enrichment, 'offer_click');
    if (offerRule) {
      destUrl = offerRule;
      // Rule destinations match origin: raw URL + allowlisted params
      const finalUrl = appendAllowedParams(destUrl, clickId, params, campaign, true);
      ctx.waitUntil(
        postClick(env, buildPayload(campaign, clickId, params, enrichment, {
          offer_id: destination.id,
          landing_page_id: null,
          is_direct_to_offer: true,
          redirect_rule_matched: true,
          destination_url: finalUrl,
          cost: captureCost(campaign, params),
          slug_id: campaign.slug_id || null,
        }))
      );
      return redirect(finalUrl);
    }
  }

  const finalUrl = buildDestinationUrl(destUrl, clickId, params, campaign, isDto);
  ctx.waitUntil(
    postClick(env, buildPayload(campaign, clickId, params, enrichment, {
      offer_id: destination.type === 'offer' ? destination.id : null,
      landing_page_id: destination.type === 'landing_page' ? destination.id : null,
      is_direct_to_offer: isDto,
      redirect_rule_matched: false,
      destination_url: finalUrl,
      cost: captureCost(campaign, params),
      slug_id: campaign.slug_id || null,
    }))
  );
  return redirect(finalUrl);
}

function isClickPath(url) {
  const path = url.pathname.replace(/\/+$/, '') || '/';
  if (path.endsWith('/go.php') || path.endsWith('/km.php')) return true;
  if (/^\/(go|km|c)(\/|$)/i.test(path)) return true;
  if (url.searchParams.has('k') && /(go|km)\.php$/i.test(path)) return true;
  return false;
}

function extractPathInfo(url) {
  const q = url.searchParams.get('k');
  if (q) {
    return { key: q.trim(), slug: null };
  }

  const path = url.pathname.replace(/\/+$/, '');
  // /km/<campaignKey>[/<slug>] or /go/<key> or /c/<key>
  // Also: /km/<slug> when first segment is a vanity slug (looked up via slug: KV)
  const m = path.match(/\/(?:go|km|c)\/([^\/\?]+)(?:\/([^\/\?]+))?/i);
  if (m) {
    const first = decodeURIComponent(m[1]);
    const second = m[2] ? decodeURIComponent(m[2]) : null;
    if (second) {
      return { key: first, slug: second };
    }
    return { key: first, slug: null };
  }
  return { key: null, slug: null };
}

async function loadCampaign(kv, pathInfo) {
  if (!kv) return null;
  const tryKeys = [];
  if (pathInfo.key) {
    tryKeys.push('camp:' + pathInfo.key);
    // Vanity slug as sole path segment
    tryKeys.push('slug:' + pathInfo.key);
  }
  if (pathInfo.slug) {
    tryKeys.push('slug:' + pathInfo.slug);
  }

  for (const k of tryKeys) {
    const raw = await kv.get(k);
    if (!raw) continue;
    try {
      return JSON.parse(raw);
    } catch {
      continue;
    }
  }
  return null;
}

function enrichRequest(request) {
  const ua = request.headers.get('user-agent') || '';
  const country = request.headers.get('cf-ipcountry') || '';
  const city = request.cf?.city || '';
  const region = request.cf?.region || request.cf?.regionCode || '';
  const parsed = parseUa(ua);
  return {
    country: country && country !== 'XX' ? country : '',
    region,
    city,
    language: (request.headers.get('accept-language') || '').split(',')[0] || '',
    user_agent: ua,
    referer: request.headers.get('referer') || '',
    ip: request.headers.get('cf-connecting-ip') || '',
    ...parsed,
  };
}

function parseUa(ua) {
  const out = {
    device: 'desktop',
    device_brand: '',
    device_model: '',
    os: '',
    os_version: '',
    browser: '',
    browser_version: '',
  };
  if (!ua) return out;

  if (/ipad|tablet/i.test(ua)) out.device = 'tablet';
  else if (/mobi|iphone|android(?!.*tablet)/i.test(ua)) out.device = 'mobile';

  if (/windows nt/i.test(ua)) out.os = 'Windows';
  else if (/android/i.test(ua)) out.os = 'Android';
  else if (/iphone|ipad|ios/i.test(ua)) out.os = 'iOS';
  else if (/mac os x/i.test(ua)) out.os = 'macOS';
  else if (/linux/i.test(ua)) out.os = 'Linux';

  const androidVer = ua.match(/Android\s+([\d.]+)/i);
  if (androidVer) out.os_version = androidVer[1];
  const iosVer = ua.match(/OS\s+([\d_]+)/i);
  if (iosVer) out.os_version = iosVer[1].replace(/_/g, '.');

  if (/edg\//i.test(ua)) out.browser = 'Edge';
  else if (/chrome\//i.test(ua) && !/chromium/i.test(ua)) out.browser = 'Chrome';
  else if (/safari\//i.test(ua) && !/chrome/i.test(ua)) out.browser = 'Safari';
  else if (/firefox\//i.test(ua)) out.browser = 'Firefox';

  const chromeVer = ua.match(/(?:Chrome|CriOS)\/([\d.]+)/i);
  const ffVer = ua.match(/Firefox\/([\d.]+)/i);
  const safVer = ua.match(/Version\/([\d.]+).*Safari/i);
  if (chromeVer) out.browser_version = chromeVer[1];
  else if (ffVer) out.browser_version = ffVer[1];
  else if (safVer) out.browser_version = safVer[1];

  return out;
}

function matchRedirectRule(campaign, params, enrichment, executeOn) {
  const rules = Array.isArray(campaign.redirect_rules) ? campaign.redirect_rules : [];
  for (const rule of rules) {
    if ((rule.execute_on || 'campaign_click') !== executeOn) continue;
    const actual = resolveRuleValue(campaign, params, enrichment, rule);
    if (actual === null || actual === undefined) continue;
    if (compareValues(String(actual), String(rule.value || ''), rule.operator || 'equals', !!rule.case_sensitive)) {
      return rule.redirect_url || null;
    }
  }
  return null;
}

function resolveRuleValue(campaign, params, enrichment, rule) {
  const tokenName = rule.token_name || '';
  const builtins = {
    'Location (Country)': enrichment.country,
    'State/Region': enrichment.region,
    'City': enrichment.city,
    'Device': enrichment.device,
    'Device Brand': enrichment.device_brand,
    'Device Model': enrichment.device_model,
    'Operating System': enrichment.os,
    'OS Version': enrichment.os_version,
    'Browser': enrichment.browser,
    'Browser Version': enrichment.browser_version,
    'IP Address': enrichment.ip,
  };
  if (Object.prototype.hasOwnProperty.call(builtins, tokenName)) {
    return builtins[tokenName];
  }

  const customs = Array.isArray(campaign.custom_tokens) ? campaign.custom_tokens : [];
  for (const t of customs) {
    if (t.name === tokenName && t.parameter) {
      return params[t.parameter] ?? null;
    }
  }

  const tsTokens = campaign.traffic_source?.tokens || [];
  for (const t of tsTokens) {
    if ((t.name || '') === tokenName && t.parameter) {
      return params[t.parameter] ?? null;
    }
  }

  // Fallback: token_source may encode parameter
  const source = rule.token_source || '';
  if (source.startsWith('param:')) {
    return params[source.slice(6)] ?? null;
  }
  return params[tokenName] ?? null;
}

function compareValues(actual, expected, operator, caseSensitive) {
  let a = actual;
  let e = expected;
  if (!caseSensitive) {
    a = a.toLowerCase();
    e = e.toLowerCase();
  }
  switch (operator) {
    case 'not_equals':
      return a !== e;
    case 'contains':
      return a.includes(e);
    case 'starts_with':
      return a.startsWith(e);
    case 'ends_with':
      return a.endsWith(e);
    case 'equals':
    default:
      return a === e;
  }
}

function resolveDestination(campaign) {
  const rotation = campaign.rotation || {};
  const lookup = campaign.destinations || {};
  const mode = rotation.mode || 'dto';

  const pickWeighted = (items) => {
    const enabled = (items || []).filter((i) => i && i.enabled !== false);
    const available = enabled.filter((i) => {
      const full = lookup[(i.type === 'landing_page' ? 'lp:' : 'offer:') + i.id];
      if (!full) return false;
      if (full.type === 'offer' && !isOfferAvailable(full)) return false;
      return true;
    });
    if (!available.length) return null;
    const total = available.reduce((s, i) => s + (Number(i.weight) || 0), 0);
    if (total <= 0) return hydrate(available[0], lookup);
    let r = Math.floor(Math.random() * total) + 1;
    let acc = 0;
    for (const item of available) {
      acc += Number(item.weight) || 0;
      if (r <= acc) return hydrate(item, lookup);
    }
    return hydrate(available[0], lookup);
  };

  if (mode === 'split') {
    const lpPercent = Number(rotation.lp_percent) || 50;
    const roll = Math.floor(Math.random() * 100) + 1;
    if (roll <= lpPercent) {
      return pickWeighted(rotation.landing_pages);
    }
    return pickWeighted(rotation.offers);
  }

  if (mode === 'lp') {
    return pickWeighted(rotation.landing_pages);
  }

  return pickWeighted(rotation.offers);
}

function hydrate(item, lookup) {
  const key = (item.type === 'landing_page' ? 'lp:' : 'offer:') + item.id;
  const full = lookup[key];
  if (!full) return null;
  return {
    id: full.id,
    type: full.type,
    url: full.url,
  };
}

function isOfferAvailable(offer) {
  // Caps are origin-only; eligibility excludes capped campaigns, but skip defensively
  if (offer.cap_enabled) return false;
  if (offer.is_24_7) return true;
  const days = offer.schedule_days;
  if (!days || !days.length || !offer.schedule_start_time || !offer.schedule_end_time) {
    return true;
  }
  try {
    const tz = offer.schedule_timezone || 'UTC';
    const now = new Date();
    const day = new Intl.DateTimeFormat('en-US', { weekday: 'long', timeZone: tz })
      .format(now)
      .toLowerCase();
    if (!days.map((d) => String(d).toLowerCase()).includes(day)) return false;
    const timeStr = new Intl.DateTimeFormat('en-GB', {
      timeZone: tz,
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
    }).format(now);
    const start = normalizeTime(offer.schedule_start_time);
    const end = normalizeTime(offer.schedule_end_time);
    if (start > end) {
      return timeStr >= start || timeStr <= end;
    }
    return timeStr >= start && timeStr <= end;
  } catch {
    return true;
  }
}

function normalizeTime(t) {
  const s = String(t);
  return s.length === 5 ? s + ':00' : s;
}

function captureCost(campaign, params) {
  const ts = campaign.traffic_source || {};
  const costKey = ts.cost_param_key || 'cost';
  if (params[costKey] !== undefined && params[costKey] !== '' && !isNaN(Number(params[costKey]))) {
    return Number(params[costKey]);
  }
  if (params.cost !== undefined && params.cost !== '' && !isNaN(Number(params.cost))) {
    return Number(params.cost);
  }
  if (campaign.default_cpc != null && !isNaN(Number(campaign.default_cpc))) {
    return Number(campaign.default_cpc);
  }
  return null;
}

function buildDestinationUrl(url, clickId, params, campaign, isToOffer) {
  let out = String(url || '');
  const hasBraceTokens = /\{[a-zA-Z0-9_]+\}/.test(out);
  const replacements = {
    '{click_id}': clickId,
    '{clickid}': clickId,
    '{campaign_id}': String(campaign.campaign_id || ''),
  };
  // Only substitute tokens that appear in the URL template (origin does replace OR append, not both)
  for (const [token, value] of Object.entries(replacements)) {
    out = out.split(token).join(value);
  }
  if (hasBraceTokens) {
    // Origin: when URL has tokens, ClickTokenReplacer runs and appendParameters is skipped.
    // Phase 1 only supports the tokens above; unsupported campaigns are kept off edge.
    if (!out.includes('click_id=')) {
      const join = out.includes('?') ? '&' : '?';
      out = out + join + 'click_id=' + encodeURIComponent(clickId);
    }
    return out;
  }
  return appendAllowedParams(out, clickId, params, campaign, !!isToOffer);
}

/**
 * Mirror Redirector::appendParameters — only pass allowlisted tokens + click_id.
 */
function appendAllowedParams(url, clickId, params, campaign, isToOffer) {
  try {
    const u = new URL(url, 'https://example.invalid');
    if (!u.searchParams.has('click_id')) {
      u.searchParams.set('click_id', clickId);
    }

    const allowed = collectAllowedParams(params, campaign, isToOffer);
    for (const [k, v] of Object.entries(allowed)) {
      if (!u.searchParams.has(k)) {
        u.searchParams.set(k, String(v));
      }
    }

    if (url.startsWith('http://') || url.startsWith('https://')) {
      return u.toString();
    }
    return u.pathname + u.search + u.hash;
  } catch {
    const join = url.includes('?') ? '&' : '?';
    return url + join + 'click_id=' + encodeURIComponent(clickId);
  }
}

function collectAllowedParams(params, campaign, isToOffer) {
  const allowed = {};
  const customs = Array.isArray(campaign.custom_tokens) ? campaign.custom_tokens : [];
  for (const t of customs) {
    const param = t.parameter;
    if (!param || params[param] === undefined) continue;
    const pass = isToOffer ? !!t.pass_to_offer : !!t.pass_to_lp;
    if (pass) allowed[param] = params[param];
  }
  const tsTokens = campaign.traffic_source?.tokens || [];
  for (const t of tsTokens) {
    const param = t.parameter;
    if (!param || params[param] === undefined) continue;
    const pass = isToOffer ? !!t.pass_to_offer : !!t.pass_to_lp;
    if (pass) allowed[param] = params[param];
  }
  return allowed;
}

function buildPayload(campaign, clickId, params, enrichment, extra) {
  return {
    click_id: clickId,
    campaign_id: campaign.campaign_id,
    slug_id: extra.slug_id || campaign.slug_id || null,
    traffic_source_id: campaign.traffic_source_id || null,
    offer_id: extra.offer_id,
    landing_page_id: extra.landing_page_id,
    is_direct_to_offer: !!extra.is_direct_to_offer,
    redirect_rule_matched: !!extra.redirect_rule_matched,
    destination_url: extra.destination_url || '',
    timestamp: Math.floor(Date.now() / 1000),
    country: enrichment.country,
    region: enrichment.region,
    city: enrichment.city,
    browser: enrichment.browser,
    browser_version: enrichment.browser_version,
    device: enrichment.device,
    device_brand: enrichment.device_brand,
    device_model: enrichment.device_model,
    language: enrichment.language,
    operating_system: enrichment.os,
    os_version: enrichment.os_version,
    referer: enrichment.referer,
    user_agent: enrichment.user_agent,
    ip: enrichment.ip,
    params,
    cost: extra.cost ?? null,
    cost_currency: 'USD',
    source: 'edge',
  };
}

async function postClick(env, payload) {
  const ingestUrl = env.INGEST_URL;
  const secret = env.INGEST_SECRET;
  if (!ingestUrl || !secret) return;

  const body = JSON.stringify(payload);
  const ts = String(Math.floor(Date.now() / 1000));
  const sig = await hmacSha256(secret, ts + '.' + body);

  try {
    const res = await fetch(ingestUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + secret,
        'X-Edge-Timestamp': ts,
        'X-Edge-Signature': sig,
      },
      body,
      redirect: 'manual',
    });
    if (res.status < 200 || res.status >= 300) {
      console.error('edge ingest non-2xx', res.status, ingestUrl);
    }
  } catch (err) {
    console.error('edge ingest failed', err);
  }
}

async function hmacSha256(secret, message) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw',
    enc.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const sig = await crypto.subtle.sign('HMAC', key, enc.encode(message));
  return [...new Uint8Array(sig)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function redirect(url) {
  return new Response(null, {
    status: 302,
    headers: {
      Location: url,
      'Cache-Control': 'no-store',
    },
  });
}

async function fallbackToOrigin(request, env) {
  const origin = env.ORIGIN_FALLBACK_URL;
  if (!origin) {
    return new Response('Edge campaign not found and origin fallback not configured', { status: 404 });
  }
  try {
    const incoming = new URL(request.url);
    const target = new URL(incoming.pathname + incoming.search, origin.replace(/\/$/, '') + '/');
    return fetch(new Request(target.toString(), request));
  } catch (err) {
    console.error('origin fallback failed', err);
    return new Response('Origin fallback failed', { status: 502 });
  }
}
