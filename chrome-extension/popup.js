'use strict';

var _tab = 'log';
function tab(name) {
  _tab = name;
  document.getElementById('plog').style.display = name === 'log' ? 'block' : 'none';
  document.getElementById('psettings').style.display = name === 'settings' ? 'block' : 'none';
  document.querySelectorAll('.tab-btn').forEach(function(b, i) {
    b.className = 'tab-btn' + ((i === 0 && name === 'log') || (i === 1 && name === 'settings') ? ' active' : '');
  });
}

function st(msg, isErr) {
  var el = document.getElementById(_tab === 'settings' ? 's-status' : 'status');
  el.textContent = msg;
  el.className = isErr ? '' : 'ok';
  setTimeout(function() { el.textContent = ''; }, 3000);
}

function reload() {
  chrome.storage.sync.get(['webAppUrl', 'webhookSecret', 'currentProject'], function(c) {
    loadProjects(c.webAppUrl, c.webhookSecret, c.currentProject);
  });
}

function loadProjects(url, secret, current) {
  if (!url || !secret) return;
  fetch(url + '?token=' + encodeURIComponent(secret) + '&action=projects')
    .then(function(r) { return r.json(); })
    .then(function(list) {
      var sel = document.getElementById('sel');
      sel.innerHTML = '<option value="Genel">Genel</option>';
      (Array.isArray(list) ? list : []).forEach(function(p) {
        var o = document.createElement('option');
        o.value = o.textContent = p;
        if (p === current) o.selected = true;
        sel.appendChild(o);
      });
    }).catch(function() {});
}

function save() {
  var url = document.getElementById('url').value.trim();
  var sec = document.getElementById('sec').value.trim();
  if (!url) { st('URL gerekli!', true); return; }
  chrome.storage.sync.set({ webAppUrl: url, webhookSecret: sec }, function() {
    st('Kaydedildi!', false);
    loadProjects(url, sec, null);
  });
}

function send() {
  var newp = document.getElementById('newp').value.trim();
  var sel = document.getElementById('sel').value;
  var project = newp || sel || 'Genel';
  chrome.storage.sync.set({ currentProject: project });
  chrome.tabs.query({ active: true, currentWindow: true }, function(tabs) {
    if (!tabs[0]) { st('Sekme bulunamadi.', true); return; }
    chrome.tabs.sendMessage(tabs[0].id, { action: 'manual_log', project: project }, function() {
      st('Kayit gonderildi!', false);
    });
  });
}

document.addEventListener('DOMContentLoaded', function() {
  chrome.storage.sync.get(['webAppUrl', 'webhookSecret', 'currentProject'], function(c) {
    if (c.webAppUrl) document.getElementById('url').value = c.webAppUrl;
    if (c.webhookSecret) document.getElementById('sec').value = c.webhookSecret;
    loadProjects(c.webAppUrl, c.webhookSecret, c.currentProject);
  });
  chrome.tabs.query({ active: true, currentWindow: true }, function(tabs) {
    if (!tabs[0]) return;
    var h = new URL(tabs[0].url).hostname;
    var map = { 'claude.ai': 'Claude', 'gemini.google.com': 'Gemini', 'perplexity.ai': 'Perplexity' };
    var found = Object.keys(map).find(function(k) { return h.indexOf(k) !== -1; });
    document.getElementById('tool-badge').textContent = found ? map[found] + ' aktif' : 'Desteksiz sayfa';
  });
  document.getElementById('sel').addEventListener('change', function() {
    chrome.storage.sync.set({ currentProject: this.value });
  });
});
