<?php
// Compresión gzip opcional para reducir tráfico en respuestas HTML.
if (
  extension_loaded('zlib')
  && !headers_sent()
  && isset($_SERVER['HTTP_ACCEPT_ENCODING'])
  && strpos((string)$_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false
  && !in_array('ob_gzhandler', ob_list_handlers(), true)
) {
  ob_start('ob_gzhandler');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CSV Viewer</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Jura:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }

    html,
    body {
      margin: 0;
      width: 100%;
      height: 100%;
      font-family: Jura, Segoe UI, Roboto, Arial, sans-serif;
      background: #0f0f0f;
      color: #f3f3f3;
    }

    #app {
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
    }

    #dropzone {
      flex: 1;
      width: 100%;
      min-height: 100dvh;
      border: 2px dashed #525252;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 24px;
      transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
      cursor: pointer;
      background: #111111;
    }

    #dropzone.active {
      background: #1a1a1a;
      border-color: #a3a3a3;
      transform: scale(0.997);
    }

    .drop-content {
      max-width: 720px;
    }

    .drop-content h1 {
      margin: 0 0 12px;
      font-size: clamp(1.8rem, 4vw, 3rem);
      line-height: 1.1;
      letter-spacing: 0.02em;
      font-weight: 600;
    }

    .drop-content p {
      margin: 0;
      font-size: 1rem;
      color: #cfcfcf;
    }

    #status {
      margin-top: 12px;
      font-size: 0.95rem;
      color: #d4d4d4;
      min-height: 1.4em;
    }

    #fileInput {
      display: none;
    }

    #tableWrap {
      display: none;
      width: 100%;
      height: 100dvh;
      overflow: auto;
      overflow-anchor: none;
      padding: 70px 16px 16px;
      background: #0a0a0a;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      background: #121212;
      border-radius: 10px;
      overflow: hidden;
    }

    thead th {
      position: sticky;
      top: 0;
      background: #1f1f1f;
      color: #f5f5f5;
      z-index: 2;
      font-weight: 600;
    }

    th,
    td {
      border: 1px solid #2c2c2c;
      padding: 8px 10px;
      font-size: 0.9rem;
      white-space: nowrap;
      text-align: left;
    }

    tbody tr {
      height: 36px;
    }

    tbody td {
      min-height: 36px;
      line-height: 20px;
    }

    th.row-number,
    td.row-number {
      width: 90px;
      min-width: 90px;
      text-align: right;
      color: #d0d0d0;
      font-variant-numeric: tabular-nums;
    }

    tbody tr:nth-child(odd) {
      background: #171717;
    }

    tbody tr:nth-child(even) {
      background: #141414;
    }

    #toolbar {
      display: none;
      position: fixed;
      left: 16px;
      right: 16px;
      top: 12px;
      z-index: 10;
      gap: 8px;
      align-items: center;
      background: rgba(16, 16, 16, 0.92);
      border: 1px solid #2a2a2a;
      border-radius: 10px;
      padding: 8px 10px;
      backdrop-filter: blur(8px);
    }

    #toolbarMeta {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 0;
      flex: 1;
    }

    #phaseBadge {
      font-size: 0.78rem;
      color: #f5f5f5;
      background: #2b2b2b;
      border: 1px solid #3a3a3a;
      border-radius: 999px;
      padding: 4px 9px;
      white-space: nowrap;
    }

    #phaseBadge.ok {
      background: #14321f;
      border-color: #24683b;
      color: #b7f6ca;
    }

    #phaseBadge.busy {
      background: #2f270f;
      border-color: #5e4a1e;
      color: #f8e2a8;
    }

    #resetBtn {
      border: 1px solid #525252;
      background: #121212;
      color: #f5f5f5;
      border-radius: 8px;
      padding: 8px 12px;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
    }

    #resetBtn:hover {
      background: #1e1e1e;
    }

    #counter {
      font-size: 0.9rem;
      color: #e5e5e5;
      background: #171717;
      border: 1px solid #2f2f2f;
      border-radius: 8px;
      padding: 7px 10px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      font-variant-numeric: tabular-nums;
      min-width: 0;
    }

    #progressWrap {
      width: 180px;
      height: 8px;
      border-radius: 999px;
      background: #1f1f1f;
      border: 1px solid #303030;
      overflow: hidden;
      flex: 0 0 auto;
    }

    #progressFill {
      height: 100%;
      width: 0%;
      background: linear-gradient(90deg, #5a5a5a, #b4b4b4);
      transition: width 0.15s ease;
    }
  </style>
</head>
<body>
  <div id="app">
    <div id="dropzone">
      <div class="drop-content">
        <h1>Suelta tu archivo CSV</h1>
        <p>Arrastra aquí tu CSV para ver todos los datos en tabla o haz clic para seleccionar.</p>
        <div id="status"></div>
      </div>
    </div>

    <div id="toolbar">
      <div id="toolbarMeta">
        <span id="phaseBadge">En espera</span>
        <span id="counter">0 filas</span>
      </div>
      <div id="progressWrap" aria-hidden="true">
        <div id="progressFill"></div>
      </div>
      <button id="resetBtn" type="button">Cargar otro CSV</button>
    </div>

    <div id="tableWrap">
      <table id="csvTable"></table>
    </div>

    <input id="fileInput" type="file" accept=".csv,text/csv" />
  </div>

  <script>
    // Referencias al DOM principal.
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('fileInput');
    const tableWrap = document.getElementById('tableWrap');
    const csvTable = document.getElementById('csvTable');
    const toolbar = document.getElementById('toolbar');
    const resetBtn = document.getElementById('resetBtn');
    const counter = document.getElementById('counter');
    const status = document.getElementById('status');
    const phaseBadge = document.getElementById('phaseBadge');
    const progressFill = document.getElementById('progressFill');

    // Parámetros de rendimiento para carga, stream y virtualización de tabla.
    const CHUNK_SIZE = 70000;
    const ROW_HEIGHT = 36;
    const OVERSCAN = 8;
    const PREVIEW_ROWS = 5000;
    const PREVIEW_BYTES = 16 * 1024 * 1024;
    const UPLOAD_CHUNK_BYTES = 8 * 1024 * 1024;
    const MAX_APPEND_PER_FRAME = 5000;
    const MAX_BROWSER_SCROLL_PX = 33000000;
    const INCOMING_HIGH_WATER = CHUNK_SIZE * 2;
    const INCOMING_LOW_WATER = Math.max(500, Math.floor(CHUNK_SIZE / 2));
    const POLL_FAST_MS = 10;
    const POLL_IDLE_MS = 220;
    const PERF_REFRESH_MS = 400;
    const POLL_LIMIT_MIN = 20000;
    const POLL_LIMIT_MAX = 140000;
    const POLL_TARGET_MS = 115;
    const SSE_STALL_MS = 8000;
    const PREFER_SSE = true;

    // Estado global de la sesión de carga/render.
    const state = {
      uploadId: '',
      nextOffset: 0,
      previewEndOffset: 0,
      skipInitialRows: 0,
      hasMore: false,
      loadComplete: false,
      pendingAuthoritativeReplace: false,
      uploadFinalized: false,
      headers: [],
      rows: [],
      sessionId: 0,
      lastRenderKey: '',
      lastRenderTotal: 0,
      pollTimer: 0,
      pollInFlight: false,
      eventSource: null,
      sseLastActivityAt: 0,
      sseWatchdogTimer: 0,
      forcePolling: false,
      endSignals: 0,
      pollDelayMs: POLL_FAST_MS,
      pollLimit: CHUNK_SIZE,
      renderRaf: 0,
      applyRaf: 0,
      incomingRows: [],
      tbody: null,
      metrics: {
        startedAt: 0,
        uploadEndedAt: 0,
        firstRowsAt: 0,
        pollCalls: 0,
        pollTimeMs: 0,
        pollRows: 0,
        renderCalls: 0,
        renderTimeMs: 0,
        applyCalls: 0,
        applyTimeMs: 0,
        completeLogged: false,
        lastUiRefreshAt: 0
      }
    };

    dropzone.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', (event) => {
      const file = event.target.files?.[0];
      if (file) handleFile(file);
    });

    ['dragenter', 'dragover'].forEach((type) => {
      dropzone.addEventListener(type, (event) => {
        event.preventDefault();
        event.stopPropagation();
        dropzone.classList.add('active');
      });
    });

    ['dragleave', 'drop'].forEach((type) => {
      dropzone.addEventListener(type, (event) => {
        event.preventDefault();
        event.stopPropagation();
        dropzone.classList.remove('active');
      });
    });

    dropzone.addEventListener('drop', (event) => {
      const file = event.dataTransfer?.files?.[0];
      if (file) handleFile(file);
    });

    tableWrap.addEventListener('scroll', () => scheduleRender());
    window.addEventListener('resize', () => scheduleRender());

    function setPhase(message, mode = 'idle') {
      status.textContent = message;
      if (phaseBadge) {
        phaseBadge.textContent = message || 'En espera';
        phaseBadge.classList.remove('ok', 'busy');
        if (mode === 'ok') {
          phaseBadge.classList.add('ok');
        } else if (mode === 'busy') {
          phaseBadge.classList.add('busy');
        }
      }
    }

    function setProgress(percent) {
      const clamped = Math.max(0, Math.min(100, Number(percent) || 0));
      progressFill.style.width = `${clamped}%`;
    }

    resetBtn.addEventListener('click', () => {
      clearCurrentSession();
      csvTable.innerHTML = '';
      fileInput.value = '';
      setPhase('', 'idle');
      setProgress(0);
      counter.textContent = '0 filas';
      tableWrap.scrollTop = 0;
      tableWrap.style.display = 'none';
      toolbar.style.display = 'none';
      dropzone.style.display = 'flex';
    });

    // Reinicia totalmente el estado de la sesión activa.
    function clearCurrentSession() {
      state.sessionId += 1;
      state.uploadId = '';
      state.nextOffset = 0;
      state.previewEndOffset = 0;
      state.skipInitialRows = 0;
      state.hasMore = false;
      state.loadComplete = false;
      state.pendingAuthoritativeReplace = false;
      state.uploadFinalized = false;
      state.headers = [];
      state.rows = [];
      state.lastRenderKey = '';
      state.lastRenderTotal = 0;
      state.tbody = null;
      state.incomingRows = [];
      if (state.pollTimer) {
        clearTimeout(state.pollTimer);
        state.pollTimer = 0;
      }
      if (state.eventSource) {
        state.eventSource.close();
        state.eventSource = null;
      }
      state.pollInFlight = false;
      state.sseLastActivityAt = 0;
      if (state.sseWatchdogTimer) {
        clearTimeout(state.sseWatchdogTimer);
        state.sseWatchdogTimer = 0;
      }
      state.forcePolling = false;
      state.endSignals = 0;
      state.pollDelayMs = POLL_FAST_MS;
      state.pollLimit = CHUNK_SIZE;
      state.metrics = {
        startedAt: 0,
        uploadEndedAt: 0,
        firstRowsAt: 0,
        pollCalls: 0,
        pollTimeMs: 0,
        pollRows: 0,
        renderCalls: 0,
        renderTimeMs: 0,
        applyCalls: 0,
        applyTimeMs: 0,
        completeLogged: false,
        lastUiRefreshAt: 0
      };
      if (state.renderRaf) {
        cancelAnimationFrame(state.renderRaf);
        state.renderRaf = 0;
      }
      if (state.applyRaf) {
        cancelAnimationFrame(state.applyRaf);
        state.applyRaf = 0;
      }
    }

    // Orquesta la subida chunked, construcción de tabla y stream incremental.
    async function handleFile(file) {
      const isCsv = file.type.includes('csv') || file.name.toLowerCase().endsWith('.csv');
      if (!isCsv) {
        alert('El archivo debe ser un CSV.');
        return;
      }

      clearCurrentSession();
      const sessionId = state.sessionId;
      state.metrics.startedAt = performance.now();

      try {
        setPhase('Preparando vista previa...', 'busy');
        setProgress(2);
        dropzone.style.pointerEvents = 'none';

        let previewReady = false;

        const uploadPromise = uploadFileInChunks(file, {
          onProgress: (percent) => {
            setPhase(`Subiendo archivo... ${percent}%`, 'busy');
            setProgress(percent);
          },
          onUploadId: (uploadId) => {
            if (!uploadId) return;
            if (!state.uploadId) {
              state.uploadId = uploadId;
            }
            state.hasMore = true;
          },
          onChunkUploaded: () => {
            if (!previewReady || sessionId !== state.sessionId || !state.uploadId) return;
            startBackgroundLoading(sessionId);
          }
        });

        const localPreview = await buildLocalPreview(file);
        if (sessionId !== state.sessionId) return;

        if (localPreview.headers.length > 0) {
          state.headers = localPreview.headers;
          state.rows = localPreview.rows;
          state.skipInitialRows = 0;
          state.previewEndOffset = Math.max(0, Number(localPreview.endByteOffset || 0));
          state.nextOffset = state.previewEndOffset;
          buildTable(state.headers);
          dropzone.style.display = 'none';
          tableWrap.style.display = 'block';
          toolbar.style.display = 'flex';
          tableWrap.scrollTop = 0;
          updateCounter();
          scheduleRender();
        }

        previewReady = true;
        if (state.uploadId) {
          setPhase('Procesando filas...', 'busy');
          startBackgroundLoading(sessionId);
        }

        setPhase('Finalizando subida...', 'busy');
        const payload = await uploadPromise;
        if (sessionId !== state.sessionId) return;

        if (!payload.ok || payload.error) {
          throw new Error(payload.error || 'No se pudo procesar el archivo.');
        }

        state.uploadFinalized = true;
        state.metrics.uploadEndedAt = performance.now();
        if (!state.uploadId) {
          state.uploadId = payload.upload_id || '';
        }
        state.hasMore = true;
        state.loadComplete = false;
        startBackgroundLoading(sessionId);
        setProgress(100);
        setPhase('Procesando filas...', 'busy');
      } catch (error) {
        setPhase('', 'idle');
        alert(error.message || 'Error procesando el CSV.');
      } finally {
        dropzone.style.pointerEvents = 'auto';
      }
    }

    // Sube el archivo en chunks secuenciales con validación de índice en backend.
    async function uploadFileInChunks(file, callbacks = {}) {
      const onProgress = typeof callbacks.onProgress === 'function' ? callbacks.onProgress : () => {};
      const onUploadId = typeof callbacks.onUploadId === 'function' ? callbacks.onUploadId : () => {};
      const onChunkUploaded = typeof callbacks.onChunkUploaded === 'function' ? callbacks.onChunkUploaded : () => {};
      const totalChunks = Math.max(1, Math.ceil(file.size / UPLOAD_CHUNK_BYTES));
      let uploadId = '';

      for (let index = 0; index < totalChunks; index += 1) {
        const start = index * UPLOAD_CHUNK_BYTES;
        const end = Math.min(file.size, start + UPLOAD_CHUNK_BYTES);
        const chunk = file.slice(start, end);

        const params = new URLSearchParams({
          name: file.name,
          index: String(index),
          total: String(totalChunks)
        });
        if (uploadId) {
          params.set('upload_id', uploadId);
        }

        const response = await fetch(`upload_chunk.php?${params.toString()}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/octet-stream'
          },
          body: chunk
        });

        const payload = await response.json();
        if (!response.ok || payload.error) {
          throw new Error(payload.error || 'Error al subir chunk de CSV.');
        }

        uploadId = payload.upload_id || uploadId;
        onUploadId(uploadId);
        const percent = Math.min(99, Math.round(((index + 1) / totalChunks) * 100));
        onProgress(percent);
        onChunkUploaded(uploadId, index, totalChunks);
      }

      const finalizeResponse = await fetch('upload_complete.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ upload_id: uploadId })
      });

      const finalizePayload = await finalizeResponse.json();
      if (!finalizeResponse.ok || finalizePayload.error) {
        throw new Error(finalizePayload.error || 'Error al finalizar la carga de CSV.');
      }

      onProgress(100);
      return { ok: true, upload_id: finalizePayload.upload_id || uploadId };
    }

    // Parse JSON defensivo para errores de backend no estructurados.
    function safeParseJson(text) {
      try {
        return JSON.parse(text || '{}');
      } catch {
        return null;
      }
    }

    function parseNdjsonRows(text) {
      if (typeof text !== 'string' || text.trim() === '') {
        return [];
      }

      const lines = text.split(/\r?\n/);
      const rows = [];
      for (let index = 0; index < lines.length; index += 1) {
        const line = lines[index].trim();
        if (!line) continue;
        const parsed = safeParseJson(line);
        if (Array.isArray(parsed)) {
          rows.push(parsed);
        }
      }
      return rows;
    }

    // Genera vista previa local para mostrar encabezados de forma inmediata.
    async function buildLocalPreview(file) {
      const bytes = Math.min(file.size, PREVIEW_BYTES);
      const text = await file.slice(0, bytes).text();
      const result = parseCsvPreview(text, PREVIEW_ROWS);
      const endByteOffset = result.endCharOffset > 0
        ? new TextEncoder().encode(text.slice(0, result.endCharOffset)).byteLength
        : 0;
      return { headers: result.headers, rows: result.rows, endByteOffset };
    }

    // Parser CSV liviano para preview (no reemplaza el parser nativo C).
    function parseCsvPreview(text, maxRows) {
      const rows = [];
      let row = [];
      let cell = '';
      let inQuotes = false;
      let lastRowEndOffset = 0;

      for (let i = 0; i < text.length; i += 1) {
        const char = text[i];
        const next = text[i + 1];

        if (char === '"') {
          if (inQuotes && next === '"') {
            cell += '"';
            i += 1;
          } else {
            inQuotes = !inQuotes;
          }
          continue;
        }

        if (!inQuotes && char === ',') {
          row.push(cell);
          cell = '';
          continue;
        }

        if (!inQuotes && (char === '\n' || char === '\r')) {
          if (char === '\r' && next === '\n') i += 1;
          row.push(cell);
          cell = '';

          if (row.length > 1 || row[0] !== '') {
            rows.push(row);
          }
          row = [];
          lastRowEndOffset = i + 1;

          if (rows.length >= maxRows + 1) break;
          continue;
        }

        cell += char;
      }

      if (!rows.length) {
        return { headers: [], rows: [], endCharOffset: 0 };
      }

      const headers = rows[0].map((v, index) => v || `Columna ${index + 1}`);
      const bodyRows = rows.slice(1, maxRows + 1);
      return { headers, rows: bodyRows, endCharOffset: lastRowEndOffset };
    }

    // Construye estructura base de tabla para render virtualizado.
    function buildTable(headers) {
      const thead = document.createElement('thead');
      const headerRow = document.createElement('tr');

      const rowNumTh = document.createElement('th');
      rowNumTh.textContent = '#';
      rowNumTh.className = 'row-number';
      headerRow.appendChild(rowNumTh);

      headers.forEach((value, index) => {
        const th = document.createElement('th');
        th.textContent = value || `Columna ${index + 1}`;
        headerRow.appendChild(th);
      });

      thead.appendChild(headerRow);
      state.tbody = document.createElement('tbody');

      csvTable.innerHTML = '';
      csvTable.appendChild(thead);
      csvTable.appendChild(state.tbody);
    }

    // Actualiza contador visible de filas cargadas.
    function getLoadedRowsCount() {
      const appliedAndQueued = state.rows.length + state.incomingRows.length;
      const backendReceived = state.metrics.pollRows;
      return Math.max(appliedAndQueued, backendReceived);
    }

    function updateCounter() {
      const loadedRows = getLoadedRowsCount();
      const base = `${loadedRows.toLocaleString('es-ES')} filas`;
      if (!state.metrics.startedAt) {
        counter.textContent = base;
        return;
      }

      const now = performance.now();
      const elapsedMs = now - state.metrics.startedAt;
      const uploadMs = state.metrics.uploadEndedAt > 0
        ? (state.metrics.uploadEndedAt - state.metrics.startedAt)
        : elapsedMs;
      const sseMs = state.metrics.sseStartedAt > 0
        ? ((state.metrics.sseEndedAt > 0 ? state.metrics.sseEndedAt : now) - state.metrics.sseStartedAt)
        : 0;
      const backendMs = Math.max(state.metrics.pollTimeMs, sseMs);
      const uiMs = state.metrics.renderTimeMs + state.metrics.applyTimeMs;

      const parts = [
        base,
        `up ${(uploadMs / 1000).toFixed(1)}s`,
        `io ${(backendMs / 1000).toFixed(1)}s`,
        `ui ${(uiMs / 1000).toFixed(1)}s`
      ];

      if (state.loadComplete) {
        parts.push('completo');
      }

      counter.textContent = parts.join(' · ');
    }

    // Marca estado completo cuando no quedan lecturas ni filas pendientes.
    function refreshLoadCompletion() {
      const isComplete = state.uploadFinalized
        && !state.hasMore
        && !state.pollInFlight
        && state.incomingRows.length === 0;

      if (isComplete === state.loadComplete) return;

      state.loadComplete = isComplete;
      if (isComplete) {
        setPhase('Carga completa', 'ok');
        setProgress(100);
      }
      updateCounter();
    }

    // Programa un render en animation frame para evitar repaints excesivos.
    function scheduleRender() {
      if (!state.tbody || state.renderRaf) return;
      state.renderRaf = requestAnimationFrame(() => {
        state.renderRaf = 0;
        renderVisibleRows();
      });
    }

    // Renderiza solo filas visibles (virtual scroll).
    function renderVisibleRows() {
      if (!state.tbody) return;
      const renderStart = performance.now();

      const total = state.rows.length;
      const dataColumnCount = Math.max(1, state.headers.length);
      const totalColumnCount = dataColumnCount + 1;
      const viewportHeight = Math.max(260, tableWrap.clientHeight - 32);
      const visibleRows = Math.ceil(viewportHeight / ROW_HEIGHT);
      const scrollTop = tableWrap.scrollTop;
      const maxStart = Math.max(0, total - visibleRows);
      const virtualTotalHeight = total * ROW_HEIGHT;
      const effectiveTotalHeight = Math.min(virtualTotalHeight, MAX_BROWSER_SCROLL_PX);
      const scrollScale = virtualTotalHeight > 0 ? (effectiveTotalHeight / virtualTotalHeight) : 1;
      const maxScrollTop = Math.max(1, effectiveTotalHeight - viewportHeight);
      const normalizedScroll = Math.max(0, Math.min(1, scrollTop / maxScrollTop));
      const baseStart = Math.min(maxStart, Math.floor(normalizedScroll * maxStart));
      const start = Math.max(0, baseStart - OVERSCAN);
      const end = Math.min(total, start + visibleRows + OVERSCAN * 2);
      const renderedRows = Math.max(0, end - start);
      const renderedHeightPx = renderedRows * ROW_HEIGHT;
      const rawTopHeightPx = Math.max(0, Math.round(start * ROW_HEIGHT * scrollScale));
      const maxTopByScrollPx = Math.max(0, Math.round(scrollTop + OVERSCAN * ROW_HEIGHT));
      const maxTopByLayoutPx = Math.max(0, Math.round(effectiveTotalHeight - renderedHeightPx));
      const topHeightPx = Math.max(0, Math.min(rawTopHeightPx, maxTopByScrollPx, maxTopByLayoutPx));
      const bottomHeightPx = Math.max(0, Math.round(effectiveTotalHeight - topHeightPx - renderedHeightPx));

      const windowKey = `${start}:${end}`;
      const windowUnchanged = windowKey === state.lastRenderKey;

      if (windowUnchanged && total === state.lastRenderTotal) return;

      // If only total changed (rows appended below viewport) just update bottom spacer
      if (windowUnchanged && total > state.lastRenderTotal && end < total) {
        state.lastRenderTotal = total;
        const lastChild = state.tbody.lastElementChild;
        if (lastChild && lastChild.dataset.spacer === 'bottom') {
          lastChild.firstElementChild.style.height = `${bottomHeightPx}px`;
          return;
        }
      }

      state.lastRenderKey = windowKey;
      state.lastRenderTotal = total;

      const fragment = document.createDocumentFragment();

      if (start > 0) {
        const spacerTop = document.createElement('tr');
        const spacerCell = document.createElement('td');
        spacerCell.colSpan = totalColumnCount;
        spacerCell.style.height = `${topHeightPx}px`;
        spacerCell.style.padding = '0';
        spacerCell.style.border = '0';
        spacerTop.appendChild(spacerCell);
        fragment.appendChild(spacerTop);
      }

      for (let index = start; index < end; index += 1) {
        const values = state.rows[index] || [];
        const tr = document.createElement('tr');

        const rowNumberTd = document.createElement('td');
        rowNumberTd.className = 'row-number';
        rowNumberTd.textContent = String(index + 1);
        tr.appendChild(rowNumberTd);

        for (let col = 0; col < dataColumnCount; col += 1) {
          const td = document.createElement('td');
          td.textContent = values[col] ?? '';
          tr.appendChild(td);
        }

        fragment.appendChild(tr);
      }

      if (end < total) {
        const spacerBottom = document.createElement('tr');
        spacerBottom.dataset.spacer = 'bottom';
        const spacerCell = document.createElement('td');
        spacerCell.colSpan = totalColumnCount;
        spacerCell.style.height = `${bottomHeightPx}px`;
        spacerCell.style.padding = '0';
        spacerCell.style.border = '0';
        spacerBottom.appendChild(spacerCell);
        fragment.appendChild(spacerBottom);
      }

      state.tbody.replaceChildren(fragment);
      state.metrics.renderCalls += 1;
      state.metrics.renderTimeMs += performance.now() - renderStart;
    }

    // Lee un lote de filas del backend para render incremental sin SSE largo.
    async function fetchRowsOnce(sessionId) {
      if (sessionId !== state.sessionId || !state.uploadId || !state.hasMore) {
        return false;
      }

      if (state.pollInFlight) {
        return false;
      }

      state.pollInFlight = true;
      const pollStart = performance.now();

      try {
        const params = new URLSearchParams({
          upload_id: state.uploadId,
          offset: String(state.nextOffset),
          limit: String(state.pollLimit),
          compact: '1'
        });

        const response = await fetch(`poll_rows.php?${params.toString()}`);
        const payload = await response.json();

        if (!response.ok || !payload || payload.error) {
          throw new Error((payload && payload.error) || 'Error leyendo filas incrementales.');
        }

        const rows = Array.isArray(payload.r)
          ? payload.r
          : (Array.isArray(payload.rows) ? payload.rows : []);

        state.nextOffset = Number((payload.n ?? payload.next_offset) ?? state.nextOffset);
        const serverHasMore = Boolean((payload.h ?? payload.has_more));
        if (serverHasMore) {
          state.endSignals = 0;
          state.hasMore = true;
        } else {
          state.endSignals += 1;
          state.hasMore = state.endSignals < 2;
        }
        enqueueRows(rows);

        if (rows.length > 0) {
          state.endSignals = 0;
          if (!serverHasMore) {
            state.endSignals = 1;
            state.hasMore = true;
          }
        }
        refreshLoadCompletion();

        state.metrics.pollCalls += 1;
        state.metrics.pollRows += rows.length;
        const pollElapsedMs = performance.now() - pollStart;
        state.metrics.pollTimeMs += pollElapsedMs;

        if (rows.length > 0) {
          if (pollElapsedMs > (POLL_TARGET_MS * 1.55) && state.pollLimit > POLL_LIMIT_MIN) {
            state.pollLimit = Math.max(POLL_LIMIT_MIN, Math.floor(state.pollLimit * 0.86));
          } else if (
            pollElapsedMs < (POLL_TARGET_MS * 0.72)
            && rows.length >= Math.floor(state.pollLimit * 0.9)
            && state.pollLimit < POLL_LIMIT_MAX
          ) {
            state.pollLimit = Math.min(POLL_LIMIT_MAX, Math.floor(state.pollLimit * 1.12));
          }
        }

        if (!state.hasMore && state.uploadFinalized && !state.metrics.completeLogged) {
          state.metrics.completeLogged = true;
          const uiMs = state.metrics.renderTimeMs + state.metrics.applyTimeMs;
          const uploadMs = state.metrics.uploadEndedAt > 0
            ? (state.metrics.uploadEndedAt - state.metrics.startedAt)
            : 0;
          console.log('[perf] resumen', {
            upload_s: +(uploadMs / 1000).toFixed(2),
            backend_s: +(state.metrics.pollTimeMs / 1000).toFixed(2),
            ui_s: +(uiMs / 1000).toFixed(2),
            poll_calls: state.metrics.pollCalls,
            poll_rows: state.metrics.pollRows,
            render_calls: state.metrics.renderCalls,
            apply_calls: state.metrics.applyCalls
          });
        }

        return rows.length > 0;
      } catch {
        if (sessionId !== state.sessionId) return;
        state.hasMore = !state.uploadFinalized || state.hasMore;
        return false;
      } finally {
        state.pollInFlight = false;
        refreshLoadCompletion();
      }
    }

    // Inicia polling corto para continuar lectura en segundo plano.
    function startBackgroundLoading(sessionId) {
      if (PREFER_SSE && !state.forcePolling) {
        startBackgroundLoadingSSE(sessionId);
        return;
      }

      if (sessionId !== state.sessionId || !state.hasMore || !state.uploadId) {
        return;
      }

      if (state.pollTimer) {
        return;
      }

      const tick = async () => {
        state.pollTimer = 0;

        if (sessionId !== state.sessionId || !state.hasMore || !state.uploadId) {
          return;
        }

        if (state.incomingRows.length >= INCOMING_HIGH_WATER) {
          state.pollTimer = setTimeout(tick, state.pollDelayMs);
          return;
        }

        const hadRows = await fetchRowsOnce(sessionId);

        if (sessionId !== state.sessionId || !state.hasMore || !state.uploadId) {
          return;
        }

        state.pollDelayMs = hadRows ? POLL_FAST_MS : (state.uploadFinalized ? POLL_FAST_MS : POLL_IDLE_MS);

        state.pollTimer = setTimeout(tick, state.pollDelayMs);
      };

      state.pollTimer = setTimeout(tick, 0);
    }

    // Inicia streaming SSE (mejor en servidores concurrentes como Apache).
    function startBackgroundLoadingSSE(sessionId) {
      if (sessionId !== state.sessionId || !state.hasMore || !state.uploadId) {
        return;
      }

      if (state.eventSource) {
        return;
      }

      if (state.pollTimer) {
        clearTimeout(state.pollTimer);
        state.pollTimer = 0;
      }

      const params = new URLSearchParams({
        upload_id: state.uploadId,
        offset: String(state.nextOffset),
        limit: String(CHUNK_SIZE)
      });

      const eventSource = new EventSource(`stream_rows.php?${params.toString()}`);
      state.eventSource = eventSource;
      state.sseLastActivityAt = performance.now();

      const armSseWatchdog = () => {
        if (state.sseWatchdogTimer) {
          clearTimeout(state.sseWatchdogTimer);
        }

        state.sseWatchdogTimer = setTimeout(() => {
          if (sessionId !== state.sessionId) return;
          if (!state.eventSource) return;
          if (!state.hasMore || !state.uploadFinalized) {
            armSseWatchdog();
            return;
          }

          const idleMs = performance.now() - state.sseLastActivityAt;
          if (idleMs < SSE_STALL_MS) {
            armSseWatchdog();
            return;
          }

          state.eventSource.close();
          state.eventSource = null;
          state.forcePolling = true;
          startBackgroundLoading(sessionId);
        }, 1200);
      };

      armSseWatchdog();

      eventSource.addEventListener('rows_ndjson', (event) => {
        if (sessionId !== state.sessionId) return;
        state.sseLastActivityAt = performance.now();
        if (!state.metrics.sseStartedAt) {
          state.metrics.sseStartedAt = performance.now();
        }
        const rows = parseNdjsonRows(event.data);
        enqueueRows(rows);

        state.metrics.pollCalls += 1;
        state.metrics.pollRows += rows.length;

        refreshLoadCompletion();
      });

      eventSource.addEventListener('meta', (event) => {
        if (sessionId !== state.sessionId) return;
        state.sseLastActivityAt = performance.now();
        const payload = safeParseJson(event.data);
        if (!payload || typeof payload !== 'object') return;
        state.nextOffset = Number(payload.next_offset ?? state.nextOffset);
        state.hasMore = Boolean(payload.has_more);
        refreshLoadCompletion();
      });

      eventSource.addEventListener('error', (event) => {
        if (sessionId !== state.sessionId) return;
        const payload = safeParseJson(event.data || '');
        if (payload && payload.error) {
          console.warn('[sse] server error', payload.error);
        }
        if (state.eventSource) {
          state.eventSource.close();
          state.eventSource = null;
        }
        if (state.sseWatchdogTimer) {
          clearTimeout(state.sseWatchdogTimer);
          state.sseWatchdogTimer = 0;
        }
        if (state.hasMore) {
          state.forcePolling = true;
          startBackgroundLoading(sessionId);
        }
      });

      eventSource.addEventListener('end', () => {
        if (sessionId !== state.sessionId) return;
        state.metrics.sseEndedAt = performance.now();
        state.hasMore = false;
        if (state.eventSource) {
          state.eventSource.close();
          state.eventSource = null;
        }
        if (state.sseWatchdogTimer) {
          clearTimeout(state.sseWatchdogTimer);
          state.sseWatchdogTimer = 0;
        }
        refreshLoadCompletion();
      });

    }

    // Encola filas de entrada para aplicación por lotes.
    function enqueueRows(rows) {
      if (!Array.isArray(rows) || rows.length === 0) return;
      for (let index = 0; index < rows.length; index += 1) {
        state.incomingRows.push(rows[index]);
      }
      scheduleApplyIncomingRows();
    }

    // Agenda aplicación de la cola de filas en el siguiente frame.
    function scheduleApplyIncomingRows() {
      if (state.applyRaf) return;
      state.applyRaf = requestAnimationFrame(() => {
        state.applyRaf = 0;
        applyIncomingRowsBatch();
      });
    }

    // Aplica filas por lotes con control de backpressure.
    function applyIncomingRowsBatch() {
      if (state.incomingRows.length === 0) return;
      const applyStart = performance.now();

      const batch = state.incomingRows.splice(0, MAX_APPEND_PER_FRAME);
      if (state.pendingAuthoritativeReplace && batch.length > 0) {
        state.rows = [];
        state.pendingAuthoritativeReplace = false;
        state.lastRenderKey = '';
        state.lastRenderTotal = 0;
      }
      for (let index = 0; index < batch.length; index += 1) {
        state.rows.push(batch[index]);
      }
      if (!state.metrics.firstRowsAt && batch.length > 0) {
        state.metrics.firstRowsAt = performance.now();
      }
      const now = performance.now();
      if ((now - state.metrics.lastUiRefreshAt) >= PERF_REFRESH_MS) {
        updateCounter();
        state.metrics.lastUiRefreshAt = now;
      }
      scheduleRender();

      if (state.incomingRows.length > 0) {
        scheduleApplyIncomingRows();
      } else {
        updateCounter();
      }

      if (!state.pollTimer && !state.pollInFlight && state.incomingRows.length <= INCOMING_LOW_WATER && state.hasMore) {
        startBackgroundLoading(state.sessionId);
      }

      state.metrics.applyCalls += 1;
      state.metrics.applyTimeMs += performance.now() - applyStart;
      refreshLoadCompletion();
    }

    
  </script>
</body>
</html>
