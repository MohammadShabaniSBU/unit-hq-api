/**
 * First-party ad touch tag. No dependencies.
 * Stores nothing until ad_storage consent. A click id is lost if the
 * visitor leaves the landing page before that consent.
 */
(function () {
  var COOKIE_DAYS = 90
  var TOUCH_CAP = 1800
  var captured = false

  function readCookie (name) {
    var parts = document.cookie ? document.cookie.split('; ') : []
    var prefix = name + '='
    var i
    for (i = 0; i < parts.length; i++) {
      if (parts[i].indexOf(prefix) === 0) {
        try {
          return decodeURIComponent(parts[i].substring(prefix.length))
        } catch (e) {
          return ''
        }
      }
    }
    return ''
  }

  function writeCookie (name, value) {
    document.cookie = name + '=' + encodeURIComponent(value)
      + '; Max-Age=' + (COOKIE_DAYS * 86400)
      + '; Path=/; SameSite=Lax'
  }

  function randomVid () {
    var chars = 'abcdef0123456789'
    var out = ''
    var i
    if (window.crypto && window.crypto.getRandomValues) {
      var bytes = new Uint8Array(32)
      window.crypto.getRandomValues(bytes)
      for (i = 0; i < 32; i++) out += chars[bytes[i] % 16]
      return out
    }
    for (i = 0; i < 32; i++) out += chars[Math.floor(Math.random() * 16)]
    return out
  }

  function readSignals () {
    var params = new URLSearchParams(window.location.search)
    return {
      gclid: params.get('gclid') || '',
      gbraid: params.get('gbraid') || '',
      wbraid: params.get('wbraid') || '',
      fbclid: params.get('fbclid') || '',
      oai_ref: params.get('oai_ref') || '',
      utm_source: params.get('utm_source') || '',
      utm_medium: params.get('utm_medium') || '',
      utm_campaign: params.get('utm_campaign') || '',
      utm_term: params.get('utm_term') || '',
      utm_content: params.get('utm_content') || ''
    }
  }

  function hasPaid (signals) {
    return signals.gclid || signals.gbraid || signals.wbraid || signals.fbclid || signals.oai_ref
      || signals.utm_source || signals.utm_medium || signals.utm_campaign
      || signals.utm_term || signals.utm_content
  }

  function put (touch, key, value) {
    if (value) touch[key] = value
  }

  function capture (consent) {
    var signals = readSignals()
    if (!hasPaid(signals)) return

    var fbc = readCookie('_fbc')
    if (!fbc && signals.fbclid) {
      fbc = 'fb.1.' + Date.now() + '.' + signals.fbclid
    }

    var touch = {
      occurred_at: Date.now(),
      landing_url: String(window.location.href || '').slice(0, 1000),
      referrer: String(document.referrer || '').slice(0, 1000),
      consent: {
        ad_storage: true,
        ad_user_data: consent.ad_user_data === true,
        ad_personalization: consent.ad_personalization === true
      }
    }
    put(touch, 'gclid', signals.gclid)
    put(touch, 'gbraid', signals.gbraid)
    put(touch, 'wbraid', signals.wbraid)
    put(touch, 'fbc', fbc)
    put(touch, 'fbp', readCookie('_fbp'))
    put(touch, 'oai_ref', signals.oai_ref)
    put(touch, 'utm_source', signals.utm_source)
    put(touch, 'utm_medium', signals.utm_medium)
    put(touch, 'utm_campaign', signals.utm_campaign)
    put(touch, 'utm_term', signals.utm_term)
    put(touch, 'utm_content', signals.utm_content)
    writeCookie('_kv_touch', JSON.stringify(touch))
  }

  function ensureVid () {
    if (!readCookie('_kv_vid')) writeCookie('_kv_vid', randomVid())
  }

  function grant (consent) {
    var first = !captured
    captured = true
    if (first) capture(consent)
    ensureVid()
  }

  function granted (value) {
    return value === true || value === 'granted'
  }

  function inspect (entry) {
    if (!entry || entry[0] !== 'consent' || !entry[2]) return
    var params = entry[2]
    if (!granted(params.ad_storage)) return
    grant({
      ad_user_data: granted(params.ad_user_data),
      ad_personalization: granted(params.ad_personalization)
    })
  }

  function watchDataLayer () {
    var dl = window.dataLayer = window.dataLayer || []
    var i
    for (i = 0; i < dl.length; i++) inspect(dl[i])
    var original = dl.push
    dl.push = function () {
      var result = original.apply(dl, arguments)
      var n
      for (n = 0; n < arguments.length; n++) inspect(arguments[n])
      return result
    }
  }

  function base64url (text) {
    var bytes = unescape(encodeURIComponent(text))
    return btoa(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
  }

  function fit (touch) {
    var encoded = base64url(JSON.stringify(touch))
    if (encoded.length <= TOUCH_CAP) return encoded
    delete touch.utm_term
    delete touch.utm_content
    if (touch.landing_url) touch.landing_url = String(touch.landing_url).slice(0, 500)
    encoded = base64url(JSON.stringify(touch))
    if (encoded.length <= TOUCH_CAP) return encoded
    delete touch.referrer
    delete touch.landing_url
    encoded = base64url(JSON.stringify(touch))
    return encoded.length <= TOUCH_CAP ? encoded : null
  }

  function touchParam () {
    var raw = readCookie('_kv_touch')
    if (!raw) return null
    var touch
    try {
      touch = JSON.parse(raw)
    } catch (e) {
      return null
    }
    if (!touch || typeof touch !== 'object') return null
    var vid = readCookie('_kv_vid')
    if (vid) touch.visitor_key = vid
    return fit(touch)
  }

  window.keevaris = function (command, payload) {
    if (command === 'consent') {
      if (payload && granted(payload.ad_storage)) {
        grant({
          ad_user_data: granted(payload.ad_user_data),
          ad_personalization: granted(payload.ad_personalization)
        })
      }
      return
    }
    if (command === 'touch') return touchParam()
  }

  watchDataLayer()
})()
