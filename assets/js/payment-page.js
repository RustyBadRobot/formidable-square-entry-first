(() => {
  const pollIntervalMs = 3000;
  const pollTimeoutMs = 120000;
  const nonFinalStatuses = ['pending', 'checkout_created', 'awaiting_payment', 'processing'];

  document.addEventListener('click', async (event) => {
    const button = event.target.closest('.frm-square-hc-button');
    if (!button) {
      return;
    }

    const wrapper = button.closest('.frm-square-hc-card');
    const feedback = wrapper ? wrapper.querySelector('.frm-square-hc-feedback') : null;

    button.disabled = true;
    if (feedback) {
      feedback.textContent = frmSquareHostedCheckout.labels.processing;
    }

    try {
      const response = await fetch(frmSquareHostedCheckout.restUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          entry: button.dataset.entry || '',
          token: button.dataset.token || '',
          retry: button.dataset.retry === '1'
        })
      });

      const payload = await response.json();

      if (!response.ok || !payload.success || !payload.checkoutUrl) {
        throw new Error(payload.message || frmSquareHostedCheckout.labels.failed);
      }

      window.location.href = payload.checkoutUrl;
    } catch (error) {
      button.disabled = false;
      if (feedback) {
        feedback.textContent = error.message || frmSquareHostedCheckout.labels.failed;
      }
    }
  });

  const statusCard = document.querySelector('[data-payment-status-poll="1"]');
  if (!statusCard || !frmSquareHostedCheckout.statusRestUrl) {
    return;
  }

  const feedback = statusCard.querySelector('.frm-square-hc-feedback');
  const statusLabel = statusCard.querySelector('.frm-square-hc-status-label');
  const startedAt = Date.now();

  const pollStatus = async () => {
    if (Date.now() - startedAt >= pollTimeoutMs) {
      if (feedback) {
        feedback.textContent = frmSquareHostedCheckout.labels.statusWaiting;
      }
      return;
    }

    const params = new URLSearchParams({
      entry: statusCard.dataset.entry || '',
      token: statusCard.dataset.token || ''
    });

    try {
      const response = await fetch(`${frmSquareHostedCheckout.statusRestUrl}?${params.toString()}`, {
        method: 'GET',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json'
        }
      });
      const payload = await response.json();

      if (!response.ok || !payload.success) {
        throw new Error(payload.message || '');
      }

      if (payload.message && statusLabel) {
        statusLabel.textContent = payload.message;
      }

      if (payload.isSucceeded) {
        window.location.href = payload.reloadUrl || window.location.href;
        return;
      }

      if (payload.isFinal || !nonFinalStatuses.includes(payload.status)) {
        return;
      }
    } catch (error) {
      if (feedback) {
        feedback.textContent = '';
      }
    }

    window.setTimeout(pollStatus, pollIntervalMs);
  };

  window.setTimeout(pollStatus, pollIntervalMs);
})();
