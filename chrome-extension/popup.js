function showTab(name) {
  document.getElementById('tab-log').style.display = name === 'log' ? 'block' : 'none';
  document.getElementById('tab-settings').style.display = name === 'settings' ? 'block' : 'none';
  document.getElementById('tab-log-btn').className = 'tab-btn' + (name === 'log' ? ' active' : '');
  document.getElementById('tab-settings-btn').className = 'tab-btn' + (name === 'settings' ? ' active' : '');
}

function setStatus(msg, isSettings) {
  var el = document.getElementById(isSettings ? 'settings-status' : 'status');
  el.textContent = msg;
  setTimeout(function() { el.textContent = ''; }, 3000);
}

function loadSettings() {
  chrome.storage.sync.get(['webAppUrl', 'webhookSecret', 'currentProject'], function(cfg) {
    if (cfg.webAppUrl) document.getElementById('webAppUrl').value = cfg.webAppUrl;
    if (cfg.webhookSecret) document.getElementById('webhookSecret').value = cfg.webhookSecret;
    if (cfg.webAppUrl && cfg.webhookSecret) {
      loadProjects(cfg.webAppUrl, cfg.webhookSecret, cfg.currentProject);
    }
  });
  chrome.tabs.query({ active: true, currentWindow: true }, function(tabs) {
    if (!tabs[0]) return;
    var host = new URL(tabs[0].url).hostname;
    var toolMap = { 'claude.ai': 'Claude', 'gemini.google.com': 'Gemini', 'perplexity.ai': 'Perplexity' };
    var toolName = Object.keys(toolMap).find(function(k) { return host.indexOf(k) !== -1; });
    var infoEl = document.getElementById('tool-info');
    if (toolName) {
      infoEl.innerHTML = 'Aktif: <span class="tool-badge">' + toolMap[toolName] + '</span>';
    } else {
      infoEl.textContent = 'Desteklenen bir AI sayfasinda degilsiniz.';
    }
  });
}

function loadProjects(url, secret, current) {
  if (!url || !secret) return;
  fetch(url + '?token=' + encodeURIComponent(secret) + '&action=projects')
    .then(function(r) { return r.json(); })
    .then(function(projects) {
      var sel = document.getElementById('projectSelect');
      sel.innerHTML = '<option value="Genel">Genel</option>';
      (projects || []).forEach(function(p) {
        var opt = document.createElement('option');
        opt.value = p;
        opt.textContent = p;
        if (p === current) opt.selected = true;
        sel.appendChild(opt);
      });
    })
    .catch(function() {});
}

function saveSettings() {
  var webAppUrl = document.getElementById('webAppUrl').value.trim();
  var webhookSecret = document.getElementById('webhookSecret').value.trim();
  chrome.storage.sync.set({ webAppUrl: webAppUrl, webhookSecret: webhookSecret }, function() {
    setStatus('Ayarlar kaydedildi!', true);
  });
}

function manualLog() {
  var newProject = document.getElementById('newProject').value.trim();
  var selectedProject = document.getElementById('projectSelect').value;
  var project = newProject || selectedProject || 'Genel';
  chrome.storage.sync.set({ currentProject: project });
  chrome.tabs.query({ active: true, currentWindow: true }, function(tabs) {
    if (!tabs[0]) { setStatus('Aktif sekme bulunamadi.'); return; }
    chrome.tabs.sendMessage(tabs[0].id, { action: 'manual_log', project: project }, function() {
      setStatus('Kayit gonderildi!');
    });
  });
}

document.addEventListener('DOMContentLoaded', loadSettings);
document.getElementById('projectSelect').addEventListener('change', function() {
  chrome.storage.sync.set({ currentProject: this.value });
});
