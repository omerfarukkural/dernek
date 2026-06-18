chrome.runtime.onMessage.addListener(function(msg, sender, sendResponse) {
  if (msg.action === 'log_conversation') {
    chrome.storage.sync.get(['webAppUrl', 'webhookSecret', 'currentProject'], function(cfg) {
      if (!cfg.webAppUrl || !cfg.webhookSecret) {
        console.warn('AI Takipci: Web App URL veya Secret ayarlanmamis.');
        return;
      }
      var payload = JSON.stringify({
        token: cfg.webhookSecret,
        type: 'conversation',
        device: 'chrome-extension',
        tool: msg.tool,
        project: msg.project || cfg.currentProject || 'Genel',
        prompt_summary: msg.prompt_summary || '',
        response_summary: msg.response_summary || '',
        tokens_used: estimateTokens(msg.prompt_summary, msg.response_summary),
        url: msg.url || ''
      });
      fetch(cfg.webAppUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: payload
      }).catch(function(e) {
        console.error('AI Takipci POST hatasi:', e);
      });
    });
  }
});

function estimateTokens(prompt, response) {
  var text = (prompt || '') + (response || '');
  return Math.ceil(text.length / 4);
}
