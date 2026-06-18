(function() {
  'use strict';
  var TOOL_MAP = {
    'claude.ai': 'claude',
    'gemini.google.com': 'gemini',
    'perplexity.ai': 'perplexity'
  };
  var currentTool = null;
  Object.keys(TOOL_MAP).forEach(function(k) {
    if (location.hostname.indexOf(k) !== -1) currentTool = k;
  });
  if (!currentTool) return;
  var toolName = TOOL_MAP[currentTool];

  function extractConversation() {
    var promptText = '';
    var responseText = '';
    if (toolName === 'claude') {
      var userMsgs = document.querySelectorAll('[data-testid="human-turn-content"]');
      var aiMsgs = document.querySelectorAll('[data-testid="assistant-turn-content"]');
      if (userMsgs.length) promptText = userMsgs[userMsgs.length - 1].innerText.trim().substring(0, 500);
      if (aiMsgs.length) responseText = aiMsgs[aiMsgs.length - 1].innerText.trim().substring(0, 1000);
    } else if (toolName === 'gemini') {
      var queries = document.querySelectorAll('.query-text');
      var responses = document.querySelectorAll('.response-container');
      if (queries.length) promptText = queries[queries.length - 1].innerText.trim().substring(0, 500);
      if (responses.length) responseText = responses[responses.length - 1].innerText.trim().substring(0, 1000);
    } else if (toolName === 'perplexity') {
      var paras = document.querySelectorAll('.prose p');
      if (paras.length) responseText = paras[0].innerText.trim().substring(0, 1000);
    }
    return { promptText: promptText, responseText: responseText };
  }

  function sendToBackground(project, prompt, response) {
    chrome.runtime.sendMessage({
      action: 'log_conversation',
      tool: toolName,
      project: project,
      prompt_summary: prompt,
      response_summary: response,
      url: location.href
    });
  }

  var lastLogged = false;
  var observer = new MutationObserver(function() {
    var stopBtns = document.querySelectorAll('[aria-label="Stop"]');
    if (stopBtns.length === 0 && !lastLogged) {
      lastLogged = true;
      setTimeout(function() {
        chrome.storage.sync.get(['currentProject'], function(result) {
          var project = result.currentProject || 'Genel';
          var extracted = extractConversation();
          if (extracted.promptText || extracted.responseText) {
            sendToBackground(project, extracted.promptText, extracted.responseText);
          }
        });
        lastLogged = false;
      }, 3000);
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });

  chrome.runtime.onMessage.addListener(function(msg) {
    if (msg.action === 'manual_log') {
      var extracted = extractConversation();
      sendToBackground(msg.project, extracted.promptText, extracted.responseText);
    }
  });
})();
