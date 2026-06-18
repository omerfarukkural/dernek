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
  const targetHostHeader = req.headers['x-target-host'];
  if (!targetHostHeader) {
    res.writeHead(400);
    res.end('X-Target-Host header gerekli');
    return;
  }
  const toolName = AI_ENDPOINTS[targetHostHeader] || 'unknown';
  let body = '';
  req.on('data', chunk => { body += chunk; });
  req.on('end', () => {
    const options = {
      hostname: targetHostHeader,
      path: req.url,
      method: req.method,
      headers: Object.assign({}, req.headers, { host: targetHostHeader })
    };
    delete options.headers['x-target-host'];

    const proxyReq = https.request(options, proxyRes => {
      let responseBody = '';
      proxyRes.on('data', chunk => { responseBody += chunk; });
      proxyRes.on('end', () => {
        res.writeHead(proxyRes.statusCode, proxyRes.headers);
        res.end(responseBody);
        if (toolName !== 'unknown' && WEBHOOK_URL && WEBHOOK_SECRET) {
          logToSheets(toolName, body, responseBody).catch(err => console.error('Log error:', err.message));
        }
      });
    });
    proxyReq.on('error', e => {
      console.error('Proxy error:', e.message);
      res.writeHead(502);
      res.end('Proxy error: ' + e.message);
    });
    if (body) proxyReq.write(body);
    proxyReq.end();
  });
});

async function logToSheets(tool, requestBody, responseBody) {
  let reqData = {}, resData = {};
  try { reqData = JSON.parse(requestBody); } catch (e) {}
  try { resData = JSON.parse(responseBody); } catch (e) {}
  const promptText = extractPrompt(reqData, tool);
  const responseText = extractResponse(resData, tool);
  const tokens = extractTokens(resData);
  const payload = JSON.stringify({
    token: WEBHOOK_SECRET,
    type: 'conversation',
    device: 'mac-proxy',
    tool,
    project: DEFAULT_PROJECT,
    prompt_summary: promptText.substring(0, 500),
    response_summary: responseText.substring(0, 1000),
    tokens_used: tokens
  });
  const u = new URL(WEBHOOK_URL);
  return new Promise((resolve, reject) => {
    const options = {
      hostname: u.hostname,
      path: u.pathname + u.search,
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) }
    };
    const req = https.request(options, res => {
      res.on('data', () => {});
      res.on('end', resolve);
    });
    req.on('error', reject);
    req.write(payload);
    req.end();
  });
}

function extractPrompt(data, tool) {
  if (tool === 'claude' && data.messages) return data.messages.map(m => typeof m.content === 'string' ? m.content : JSON.stringify(m.content)).join(' ');
  if (tool === 'gemini' && data.contents) return JSON.stringify(data.contents).substring(0, 500);
  if (data.messages && data.messages[0]) return String(data.messages[0].content || '');
  return JSON.stringify(data).substring(0, 300);
}

function extractResponse(data, tool) {
  if (tool === 'claude' && data.content) return data.content.map(c => c.text || '').join(' ');
  if (tool === 'gemini' && data.candidates) return (data.candidates[0] && data.candidates[0].content && data.candidates[0].content.parts) ? data.candidates[0].content.parts.map(p => p.text || '').join(' ') : '';
  if (data.choices && data.choices[0]) return String(data.choices[0].message && data.choices[0].message.content || '');
  return JSON.stringify(data).substring(0, 500);
}

function extractTokens(data) {
  if (data.usage) return (data.usage.input_tokens || data.usage.prompt_tokens || 0) + (data.usage.output_tokens || data.usage.completion_tokens || 0);
  if (data.usageMetadata) return (data.usageMetadata.promptTokenCount || 0) + (data.usageMetadata.candidatesTokenCount || 0);
  return 0;
}

server.listen(PORT, '127.0.0.1', () => {
  console.log('AI Proxy aktif: http://127.0.0.1:' + PORT);
  console.log('Desteklenen araclar: Claude, Gemini, Perplexity');
});
