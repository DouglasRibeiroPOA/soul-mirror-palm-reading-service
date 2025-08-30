<script>
document.addEventListener('DOMContentLoaded', function () {
  const urlParams = new URLSearchParams(window.location.search);
  const showAccessDenied = urlParams.get('show_access_denied');
  if (showAccessDenied !== 'true') return;

  const modalHTML = `
  <div id="accessDeniedModal" style="
      position: fixed; inset: 0;
      background-color: rgba(0,0,0,0.7);
      display: flex; justify-content: center; align-items: center;
      z-index: 9999;
      font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,Cantarell,'Open Sans','Helvetica Neue',sans-serif;">
    <div role="dialog" aria-modal="true" aria-labelledby="adm_title" style="
        background-color: #f5f5f5;
        padding: 2.5rem; border-radius: 12px;
        max-width: 500px; width: 90%; text-align: center;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        animation: fadeIn 0.3s ease-out;">
      <svg style="width:60px;height:60px;margin-bottom:1.5rem;color:#d32f2f;" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
      <h2 id="adm_title" style="margin:0 0 1rem 0;color:#2d3748;font-size:1.5rem;font-weight:600;">
        Access Requires Credits
      </h2>
      <p style="color:#4a5568;line-height:1.6;margin-bottom:2rem;font-size:1.05rem;">
        You currently don't have enough credits to access this content.
        Explore our premium offerings to unlock full access to all features and resources.
      </p>
      <div style="display:flex;justify-content:center;gap:1rem;">
        <button id="goToOfferingsBtn" type="button" style="
            background-color:#38a169;color:white;border:none;
            padding:0.75rem 1.5rem;border-radius:8px;
            font-weight:500;cursor:pointer;transition:all 0.2s ease;">
          Close
        </button>
      </div>
    </div>
  </div>
  <style>
    @keyframes fadeIn { from {opacity:0; transform: translateY(20px);} to {opacity:1; transform: translateY(0);} }
    #goToOfferingsBtn:hover {
      background-color:#2f855a;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(56,161,105,0.3);
    }
    #goToOfferingsBtn:active { transform: translateY(0); }
  </style>`;

  document.body.insertAdjacentHTML('beforeend', modalHTML);

  function closeModal() {
    const modal = document.getElementById('accessDeniedModal');
    if (!modal) return;
    modal.style.transition = 'opacity 0.2s ease';
    modal.style.opacity = '0';
    setTimeout(() => {
      modal.remove();
      // remove query param without reload
      const url = new URL(window.location.href);
      url.searchParams.delete('show_access_denied');
      window.history.replaceState({}, '', url);
    }, 200);
  }

  // ✅ Robust: delegation covers button clicks even if HTML changes slightly
  document.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'goToOfferingsBtn') {
      e.preventDefault();
      closeModal();
    }
    // optional: click on backdrop to close
    const modal = document.getElementById('accessDeniedModal');
    if (modal && e.target === modal) {
      closeModal();
    }
  });

  // optional: Esc closes
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
  });
});
</script>
