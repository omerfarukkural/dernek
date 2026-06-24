'use strict';
const http = require('http');
const https = require('https');
const { URL } = require('url');
require('dotenv').config();

const PORT = parseInt(process.env.PROXY_PORT || '8080', 10);
const WEBHOOK_URL = process.env.APPS_SCRIPT_URL;
const WEBHOOK_SECRET = process.env.WEBHOOK_SECRET;
const DEFAULT_PROJECT = process.env.DEFAULT_PROJECT || 'Genel';

const AI_ENDPOINTS = {
  'api.anthropic.com': 'claude',
  'generativelanguage.googleapis.com': 'gemini',
  'api.perplexity.ai': 'perplexity'
};

const server = http.createServer((req, res) => {
  const targetHost = req.headers['x-target-host'];
  if (!targetHost) {
    res.writeHead(400);
    res.end(JSON.stringify({ error: 'X-Target-Host header gerekli' }));
    return;
  }
  const toolName = AI_ENDPOINTS[targetHost] || 'unknown';
  let body = '';
  req.on('data', chunk => { body += chunk; });
  req.on('end', () => {
    const headers = Object.assign({}, req.headers, { host: targetHost });
    delete headers['x-target-host'];
    const opts = { hostname: targetHost, path: req.url, method: req.method, headers };
    const proxyReq = https.request(opts, proxyRes => {
      let responseBody = '';
      proxyRes.on('data', chunk => { responseBody += chunk; });
      proxyRes.on('end', () => {
        res.writeHead(proxyRes.statusCode, proxyRes.headers);
        res.end(responseBody);
        if (toolName !== 'unknown' && WEBHOOK_URL && WEBHOOK_SECRET) {
          logToSheets(toolName, body, responseBody).catch(e => console.error('[Proxy] Log error:', e.message));
        }
      });
    });
    proxyReq.on('error', e => { res.writeHead(502); res.end('Proxy error: ' + e.message); });
    if (body) proxyReq.write(body);
    proxyReq.end();
  });
});

async function logToSheets(tool, reqBody, resBody) {
  let req = {}, res = {};
  try { req = JSON.parse(reqBody); } catch (e) {}
  try { res = JSON.parse(resBody); } catch (e) {}
  const prompt = extractPrompt(req, tool);
  const response = extractResponse(res, tool);
  const tokens = extractTokens(res);
  const payload = JSON.stringify({
    token: WEBHOOK_SECRET, type: 'conversation',
    device: 'mac-proxy', tool, project: DEFAULT_PROJECT,
    prompt_summary: prompt.substring(0, 500),
    response_summary: response.substring(0, 1000),
    tokens_used: tokens
  });
  const u = new URL(WEBHOOK_URL);
  return new Promise((resolve, reject) => {
    const options = { hostname: u.hostname, path: u.pathname + u.search, method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) } };
    const r = https.request(options, resp => { resp.resume(); resp.on('end', resolve); });
    r.on('error', reject);
    r.write(payload); r.end();
  });
}

function extractPrompt(d, tool) {
  if (tool === 'claude' && d.messages) return d.messages.map(m => typeof m.content === 'string' ? m.content : JSON.stringify(m.content)).join(' ');
  if (tool === 'gemini' && d.contents) return JSON.stringify(d.contents).substring(0, 500);
  if (d.messages && d.messages[0]) return String(d.messages[0].content || '');
  return JSON.stringify(d).substring(0, 300);
}

function extractResponse(d, tool) {
  if (tool === 'claude' && d.content) return d.content.map(c => c.text || '').join(' ');
  if (tool === 'gemini' && d.candidates && d.candidates[0]) {
    const parts = (d.candidates[0].content || {}).parts || [];
    return parts.map(p => p.text || '').join(' ');
  }
  if (d.choices && d.choices[0] && d.choices[0].message) return String(d.choices[0].message.content || '');
  return JSON.stringify(d).substring(0, 500);
}

function extractTokens(d) {
  if (d.usage) return (d.usage.input_tokens || d.usage.prompt_tokens || 0) + (d.usage.output_tokens || d.usage.completion_tokens || 0);
  if (d.usageMetadata) return (d.usageMetadata.promptTokenCount || 0) + (d.usageMetadata.candidatesTokenCount || 0);
  return 0;
}

server.listen(PORT, '127.0.0.1', () => {
  console.log('[AI Proxy] Calistirildi: http://127.0.0.1:' + PORT);
  console.log('[AI Proxy] Desteklenen: Claude (api.anthropic.com), Gemini, Perplexity');
  console.log('[AI Proxy] Proje: ' + DEFAULT_PROJECT);
});

process.on('uncaughtException', e => console.error('[AI Proxy] Uncaught:', e.message));
