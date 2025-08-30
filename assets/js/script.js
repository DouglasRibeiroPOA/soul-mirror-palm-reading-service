// ── TOKEN CAPTURE ON SERVICE PAGES ────────────────────────
; (function () {
  const params = new URLSearchParams(window.location.search);
  const token = params.get('token');
  if (!token) return;

  let cookie = `sm_jwt=${token};path=/;samesite=lax;`;
  if (location.protocol === 'https:') cookie = `sm_jwt=${token};path=/;secure;samesite=lax;`;
  document.cookie = cookie;

  params.delete('token');
  const clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
  history.replaceState(null, '', clean);
})();
// ─────────────────────────────────────────────────────────


// ── FORCE-INTRO / BLOCKED-EMAIL COOKIES ──────────────────
(function(){
  window.HAI = window.HAI || {};

  function getCookie(name){
    const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[-[\]{}()*+?.,\\^$|#\s]/g,'\\$&') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }
  function clearCookie(name){
    document.cookie = name + '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT' + 
      (location.protocol==='https:'?'; secure':'') + '; samesite=lax';
  }

  window.HAI.getBlockedEmail = function(){
    const v = getCookie('hai_block_email');
    return v ? decodeURIComponent(v) : null;
  };
  window.HAI.clearBlockFlags = function(){
    clearCookie('hai_force_intro');
    clearCookie('hai_block_email');
  };

  // On Back/BFCache restore, if server set hai_force_intro → push Intro
  window.addEventListener('pageshow', function(){
    const force = getCookie('hai_force_intro');
    if (!force) return;
    const hasJWT = document.cookie.match(/(?:^|; )sm_jwt=([^;]+)/);
    if (!hasJWT && window.__haiSurvey) {
      try { window.__haiSurvey.currentPageNo = 0; } catch(e){}
    }
    clearCookie('hai_force_intro');
  });
})();
// ─────────────────────────────────────────────────────────


document.addEventListener("DOMContentLoaded", function () {
  const container = document.getElementById("holistic-experience");
  const surveyContainer = document.getElementById("surveyContainer");
  const spinner = document.getElementById("spinner");
  if (!container) return;

  const surveyUrl = container.getAttribute("data-survey-url");
  const module = container.getAttribute("data-module") || "palm-reading";

  const hideInstructions = () => {
    const instr = document.getElementById("palmInstructions");
    if (instr) instr.style.display = "none";
  };

  function generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      const r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }
  const uuid = sessionStorage.getItem("holistic_user_uuid") || generateUUID();
  sessionStorage.setItem("holistic_user_uuid", uuid);

  function initializePalmCaptureUI(survey) {
    const uploadBtn = document.getElementById("uploadBtn");
    const fileInput = document.getElementById("fileInput");
    const cameraBtn = document.getElementById("cameraBtn");
    const changeBtn = document.getElementById("changeBtn");
    const video = document.getElementById("video");
    const snapBtn = document.getElementById("snapBtn");
    const preview = document.getElementById("preview");
    const instructionBlock = document.getElementById("palmInstructions");

    const showChangeOption = () => { if (changeBtn) changeBtn.style.display = "inline-block"; };
    const hideInstructions = () => { if (instructionBlock) instructionBlock.style.display = "none"; };

    if (uploadBtn) uploadBtn.onclick = () => { hideInstructions(); fileInput.click(); };

    if (fileInput) {
      fileInput.onchange = () => {
        const file = fileInput.files[0];
        if (file && file.type.startsWith("image/")) {
          const reader = new FileReader();
          reader.onload = function (e) {
            const img = new Image();
            img.onload = () => {
              const canvas = document.createElement("canvas");
              const ctx = canvas.getContext("2d");
              const maxWidth = 600, maxHeight = 800;
              const ratio = Math.min(maxWidth / img.width, maxHeight / img.height);
              const width = img.width * ratio, height = img.height * ratio;
              canvas.width = width; canvas.height = height;
              ctx.drawImage(img, 0, 0, width, height);
              const resizedBase64 = canvas.toDataURL("image/jpeg", 0.85);
              preview.src = resizedBase64; preview.style.display = "block";
              survey.setValue("palm_image", resizedBase64);
              showChangeOption();
            };
            img.src = e.target.result;
          };
          reader.readAsDataURL(file);
        }
      };
    }

    if (cameraBtn) {
      cameraBtn.onclick = async () => {
        hideInstructions(); preview.style.display = "none";
        snapBtn.style.display = "inline-block"; video.style.display = "block";
        try {
          const stream = await navigator.mediaDevices.getUserMedia({ video: true });
          video.srcObject = stream;
          snapBtn.onclick = () => {
            const canvas = document.createElement("canvas");
            const ctx = canvas.getContext("2d");
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const jpegBase64 = canvas.toDataURL("image/jpeg", 0.85);
            preview.src = jpegBase64; preview.style.display = "block";
            survey.setValue("palm_image", jpegBase64);
            showChangeOption();
            stream.getTracks().forEach((t) => t.stop());
            video.style.display = "none"; snapBtn.style.display = "none";
          };
        } catch (err) { alert("Camera access denied or not supported."); console.error(err); }
      };
    }

    if (changeBtn) {
      changeBtn.onclick = () => {
        preview.src = ""; preview.style.display = "none";
        survey.setValue("palm_image", null);
        fileInput.value = ""; video.style.display = "none";
        snapBtn.style.display = "none"; changeBtn.style.display = "none";
      };
    }
  }

  spinner.style.display = "block";

  fetch(surveyUrl)
    .then((response) => {
      if (!response.ok) throw new Error("Survey JSON not found");
      return response.json();
    })
    .then((surveyJSON) => {
      Survey.StylesManager.applyTheme("sharp");
      Survey.Serializer.addProperty("questionbase", { name: "acceptCamera:boolean", default: false });
      Survey.Serializer.addProperty("file", { name: "showCameraOption:boolean", default: true });

      const survey = new Survey.Model(surveyJSON);
      window.__haiSurvey = survey; // for pageshow guard

      // Force intro if cookie is set and no JWT
      (function(){
        const force = document.cookie.match(/(?:^|; )hai_force_intro=([^;]+)/);
        const hasJWT = document.cookie.match(/(?:^|; )sm_jwt=([^;]+)/);
        if (force && !hasJWT) {
          try { survey.currentPageNo = 0; } catch(e){}
          document.cookie = 'hai_force_intro=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT' + 
            (location.protocol==='https:'?'; secure':'') + '; samesite=lax';
        }
      })();

      if (getJWT()) survey.currentPageNo = 1;

      survey.onCurrentPageChanged.add(async (sender, options) => {
        if (options.oldCurrentPage && options.oldCurrentPage.name === "intro") {
          const data = sender.data;
          const payload = { uuid, name: data.name||'', email: data.email||'', gender: data.gender||'' };

          // UX guard: stop if blocked email
          const normalizedEmail = (data.email||'').trim().toLowerCase();
          const blockedEmail = window.HAI.getBlockedEmail && window.HAI.getBlockedEmail();
          if (!getJWT() && blockedEmail && normalizedEmail && blockedEmail === normalizedEmail) {
            const intro = sender.getPageByName('intro');
            if (intro) sender.currentPage = intro;
          }

          const mailerSpinner = document.createElement("div");
          mailerSpinner.id = "mailerSyncSpinner";
          mailerSpinner.innerHTML = "🔄 Checking your eligibility…";
          mailerSpinner.style.cssText = "font-size:0.9em;color:#555;margin-top:10px;text-align:center;";
          const surveyFooter = document.querySelector(".sv-footer") || document.querySelector(".sv-root");
          if (surveyFooter) surveyFooter.appendChild(mailerSpinner);

          try {
            const res = await fetch("/wp-admin/admin-ajax.php?action=hai_handle_subscriber", {
              method: "POST", headers: { "Content-Type": "application/json" },
              body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (!json.success) throw new Error(json.data?.message || "Eligibility check failed");

            const { status, redirect_url } = json.data;
            switch (status) {
              case "proceed":
                if (window.HAI && window.HAI.clearBlockFlags) window.HAI.clearBlockFlags();
                break;

              case "show_packages":
              case "login":
                (function(){ const intro = sender.getPageByName('intro'); if (intro) sender.currentPage = intro; })();
                if (typeof window.showOfferingsModal === 'function') {
                  window.showOfferingsModal(redirect_url);
                } else { window.location.href = redirect_url; }
                return;
            }
          } catch (err) { console.warn("Subscriber check failed:", err); }
          finally { mailerSpinner.remove(); }
        }
      });

      survey.onAfterRenderQuestion.add((s, options) => {
        if (options.question.name === "customPalmCapture") initializePalmCaptureUI(survey);
      });

      survey.showCompletedPage = false;

      function getJWT() {
        const m = document.cookie.match(/(?:^|; )sm_jwt=([^;]+)/);
        return m ? m[1] : null;
      }

      survey.onComplete.add(async (sender) => {
        const data = sender.data; const jwt = getJWT();
        const topic = jwt ? 'general' : 'intro';
        const payload = {
          uuid, name: data.name||'', email: data.email||'', gender: data.gender||'',
          module, topic,
          answers: {
            vibe_today: data.vibe_today,
            intuition_level: data.intuition_level,
            spiritual_style: data.spiritual_style,
            life_theme: data.life_theme,
            free_text: data.free_text
          },
          palm_image: data.palm_image || ''
        };
        spinner.style.display = 'block'; surveyContainer.style.display = 'none';
        try {
          const res = await fetch('/wp-admin/admin-ajax.php?action=hai_generate_report', {
            method: 'POST', credentials: 'include',
            headers: Object.assign({ 'Content-Type': 'application/json' }, jwt ? { 'Authorization': 'Bearer ' + jwt } : {}),
            body: JSON.stringify(payload)
          });
          if (!res.ok) { const text = await res.text(); throw new Error(`HTTP ${res.status}: ${text}`); }
          const json = await res.json(); spinner.style.display = 'none';

          if (json.success && json.data?.redirect) return window.location.href = json.data.redirect;
          if (json.success && json.data?.error) { alert(json.data.error); return window.location.href = json.data.purchase_url; }
          if (json.success && json.data?.html) {
            surveyContainer.innerHTML = `
              <div class="thank-you-message">
                <h3>🧘‍♀️ Thank you for sharing</h3>
                <div class="openai-result">${json.data.html}</div>
              </div>`;
            surveyContainer.style.display = 'block'; return;
          }
          console.error('Unexpected response:', json);
          alert('Something went wrong with your report. Please try again.');
        } catch (err) {
          spinner.style.display = 'none'; console.error('Fetch error:', err);
          alert('Oops, something went wrong. Please refresh and try again.');
        }
      });

      spinner.style.display = "none"; surveyContainer.style.display = "block"; survey.render(surveyContainer);
    })
    .catch((error) => {
      spinner.innerHTML = `<p style="color: red;">Failed to load survey: ${error.message}</p>`;
      console.error("Survey load error:", error);
    });
});


// — Delegated listener for our new report buttons —
document.body.addEventListener('click', function (e) {
  if (!e.target.classList.contains('btn-report')) return;
  const btn = e.target;

  // 🔐 if not logged in, go to Site A login and come back with token
  if (!ensureAuth(window.location.pathname + window.location.search)) {
    e.preventDefault();
    return;
  }

  const container = btn.closest('.report-buttons');
  const topicMap = { 'btn-topic-love': 'love', 'btn-topic-wealth': 'wealth', 'btn-topic-energy': 'energy' };
  let topic = Object.keys(topicMap).find(cls => btn.classList.contains(cls));
  if (!topic) return;
  const contextId = container.getAttribute('data-context-id') || '';

  btn.disabled = true; const original = btn.innerText; btn.innerText = '⏳ Summoning insight…';

  fetch('/wp-admin/admin-ajax.php?action=hai_generate_followup', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      // OPTIONAL: if your follow-up endpoint wants the token client-side.
      // Prefer server-side reads of the cookie, but you can pass it too:
      ...(getJWT() ? { 'Authorization': 'Bearer ' + getJWT() } : {})
    },
    body: JSON.stringify({ topic: topicMap[topic], context_id: contextId })
  })
  .then(r => r.json())
  .then(json => {
    if (json.success && json.data.html) {
      const followUp = document.createElement('div');
      followUp.className = 'follow-up-result'; followUp.innerHTML = json.data.html;
      container.after(followUp);
    } else { alert('Could not fetch more insight. Try again later.'); }
  })
  .catch(() => alert('Network error – please try again.'))
  .finally(() => { btn.disabled = false; btn.innerText = original; });
});




// === JWT helpers (Site B) ===
function getJWT() {
  const m = document.cookie.match(/(?:^|; )sm_jwt=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : null;
}

// ensure the user is authenticated; if not, bounce to Site A's branded login,
// with redirect back to THIS page so our token-capture code can store sm_jwt.
function ensureAuth(statePath) {
  const jwt = getJWT();
  if (jwt) return true;

  const redirectUri = window.location.origin + window.location.pathname; // e.g. http://palm-reading.local/palm-reading/
  const state = statePath || (window.location.pathname + window.location.search);

  const loginUrl =
    'https://soul-mirror.local/login'
    + '?redirect_uri=' + encodeURIComponent(redirectUri)
    + '&state=' + encodeURIComponent(state);

  window.location.href = loginUrl;
  return false;
}