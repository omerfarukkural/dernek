(function() {
  'use strict';

  var TOOL_MAP = {
    'claude.ai': 'claude',
    'gemini.google.com': 'gemini',
    'perplexity.ai': 'perplexity'
  };

  var toolName = null;
  Object.keys(TOOL_MAP).forEach(function(k) {
    if (location.hostname.indexOf(k) !== -1) toolName = TOOL_MAP[k];
  });
  if (!toolName) return;

  var lastLoggedAt = 0;
  var MIN_INTERVAL_MS = 5000;

  function extractConversation() {
    var prompt = '';
    var response = '';
    if (toolName === 'claude') {
      var um = document.querySelectorAll('[data-testid="human-turn-content"]');
      var am = document.querySelectorAll('[data-testid="assistant-turn-content"]');
      if (um.length) prompt = um[um.length - 1].innerText.trim().substring(0, 500);
      if (am.length) response = am[am.length - 1].innerText.trim().substring(0, 1000);
    } else if (toolName === 'gemini') {
      var qs = document.querySelectorAll('.query-text, [data-turn-role="user"] p');
      var rs = document.querySelectorAll('.response-container, model-response');
      if (qs.length) prompt = qs[qs.length - 1].innerText.trim().substring(0, 500);
      if (rs.length) response = rs[rs.length - 1].innerText.trim().substring(0, 1000);
    } else if (toolName === 'perplexity') {
      var ps = document.querySelectorAll('.break-words');
      if (ps.length > 0) response = ps[0].innerText.trim().substring(0, 1000);
      var hs = document.querySelectorAll('h1, [class*="query"]');
      if (hs.length) prompt = hs[0].innerText.trim().substring(0, 500);
    }
    return { prompt: prompt, response: response };
  }

  function maybeSendLog(project) {
    var now = Date.now();
    if (now - lastLoggedAt < MIN_INTERVAL_MS) return;
    var ex = extractConversation();
    if (!ex.prompt && !ex.response) return;
    lastLoggedAt = now;
    chrome.runtime.sendMessage({
      action: 'log_conversation',
      tool: toolName,
      project: project,
      prompt_summary: ex.prompt,
      response_summary: ex.response,
      url: location.href
    });
  }

  var observer = new MutationObserver(function() {
    var stopBtns = document.querySelectorAll('[aria-label="Stop"], button[data-testid="stop-button"], .stop-button');
    if (stopBtns.length === 0) {
      setTimeout(function() {
        chrome.storage.sync.get(['currentProject'], function(r) {
          maybeSendLog(r.currentProject || 'Genel');
        });
      }, 3000);
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });

  chrome.runtime.onMessage.addListener(function(msg) {
    if (msg.action === 'manual_log') {
      lastLoggedAt = 0;
      maybeSendLog(msg.project || 'Genel');
    }
  });
})();
