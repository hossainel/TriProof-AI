#!/usr/bin/env node
'use strict';

const http = require('http');

const BASE_URL = (process.env.TRIPROOF_BASE_URL || 'http://localhost').replace(/\/+$/, '');
const API_KEY = process.env.TRIPROOF_MCP_API_KEY || '';
const TRANSPORT = (process.env.MCP_TRANSPORT || 'stdio').toLowerCase();
const PORT = Number(process.env.MCP_HTTP_PORT || 8787);
const ENDPOINT = `${BASE_URL}/api/v1/mcp`;

async function forward(message) {
  const response = await fetch(ENDPOINT, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(API_KEY ? { 'X-TriProof-Api-Key': API_KEY } : {}),
    },
    body: JSON.stringify(message),
  });

  const text = await response.text();
  try {
    return JSON.parse(text);
  } catch {
    return {
      jsonrpc: '2.0',
      id: message?.id ?? null,
      error: { code: -32603, message: `Upstream returned HTTP ${response.status}` },
    };
  }
}

async function handleLine(line) {
  let message;
  try {
    message = JSON.parse(line);
  } catch {
    return {
      jsonrpc: '2.0',
      id: null,
      error: { code: -32700, message: 'Parse error' },
    };
  }

  try {
    return await forward(message);
  } catch (error) {
    return {
      jsonrpc: '2.0',
      id: message?.id ?? null,
      error: { code: -32603, message: error instanceof Error ? error.message : 'Bridge error' },
    };
  }
}

function startStdio() {
  let buffer = '';

  process.stdin.setEncoding('utf8');
  process.stdin.on('data', async (chunk) => {
    buffer += chunk;
    let index;
    while ((index = buffer.indexOf('\n')) >= 0) {
      const line = buffer.slice(0, index).trim();
      buffer = buffer.slice(index + 1);
      if (line === '') continue;
      const result = await handleLine(line);
      process.stdout.write(`${JSON.stringify(result)}\n`);
    }
  });

  process.stdin.on('end', () => process.exit(0));
}

function startHttp() {
  const server = http.createServer(async (request, response) => {
    if (request.method !== 'POST' || request.url !== '/mcp') {
      response.writeHead(404, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify({ error: 'Not found' }));
      return;
    }

    let body = '';
    request.setEncoding('utf8');
    for await (const chunk of request) {
      body += chunk;
    }

    const result = await handleLine(body);
    response.writeHead(200, {
      'Content-Type': 'application/json',
      'Cache-Control': 'no-store',
    });
    response.end(JSON.stringify(result));
  });

  server.listen(PORT, () => {
    process.stderr.write(`TriProof MCP Streamable HTTP bridge listening on :${PORT}/mcp\n`);
  });
}

if (TRANSPORT === 'http' || TRANSPORT === 'streamable-http') {
  startHttp();
} else {
  startStdio();
}
