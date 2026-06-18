'use strict';

chrome.runtime.onMessage.addListener(function(msg) {
  if (msg.action !== 'log_conversation') return;
  chrome.storage.sync.get(['webAppUrl', 'webhookSecret', 'currentProject'], function(cfg) {
    if (!cfg.webAppUrl || !cfg.webhookSecret) {
      console.warn('[AI Takipci] Web App URL veya Secret ayarlanmamis.');
      return;
    }
    var payload = JSON.stringify({
      token: cfg.webhookSecret,
      type: 'conversation',
      device: 'chrome-' + (navigator.platform || 'unknown'),
      tool: msg.tool,
      project: msg.project || cfg.currentProject || 'Genel',
      prompt_summary: (msg.prompt_summary || '').substring(0, 500),
      response_summary: (msg.response_summary || '').substring(0, 1000),
      tokens_used: estimateTokens(msg.prompt_summary, msg.response_summary),
      url: msg.url || ''
    });
    fetch(cfg.webAppUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: payload
    }).catch(function(err) {
      console.error('[AI Takipci] POST hatasi:', err.message);
    });
  });
});

function estimateTokens(prompt, response) {
  return Math.ceil(((prompt || '').length + (response || '').length) / 4);
}
