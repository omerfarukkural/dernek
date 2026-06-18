const SS = SpreadsheetApp.getActiveSpreadsheet();

function logConversation(data) {
  const sheet = SS.getSheetByName('📊 Konuşma Geçmişi');
  const folderLink = upsertProject(data.project, data.tool, data.device);
  const driveLink = saveToDrive(data);
  sheet.appendRow([
    new Date(),
    data.device || '',
    data.tool || '',
    data.project || '',
    data.prompt_summary || '',
    data.response_summary || '',
    data.tokens_used || 0,
    calculateCost(data.tool, data.tokens_used),
    driveLink,
    folderLink
  ]);
  updateCostDashboard();
}

function logDeploy(data) {
  const sheet = SS.getSheetByName('🌐 Hosting & Deploy Log');
  sheet.appendRow([
    new Date(),
    data.domain || '',
    data.action || '',
    data.tool || '',
    data.status || '',
    data.url || '',
    data.notes || ''
  ]);
}

function upsertProject(projectName, tool, device) {
  if (!projectName) return '';
  const sheet = SS.getSheetByName('📋 Projeler');
  const data = sheet.getDataRange().getValues();
  for (let i = 1; i < data.length; i++) {
    if (data[i][0] === projectName) {
      sheet.getRange(i + 1, 7).setValue(new Date());
      return data[i][6] || '';
    }
  }
  const folderLink = createProjectFolder(projectName, tool);
  sheet.appendRow([
    projectName, tool || '', device || '', 'Devam Ediyor',
    new Date(), new Date(), folderLink, '', '', 0, 0, ''
  ]);
  return folderLink;
}

function getProjectNames() {
  const sheet = SS.getSheetByName('📋 Projeler');
  const data = sheet.getDataRange().getValues();
  return data.slice(1).map(row => row[0]).filter(Boolean);
}

function calculateCost(tool, tokens) {
  const prices = {
    'claude': 0.000015,
    'gemini': 0.000001,
    'perplexity': 0.000008,
    'antigravity': 0.000015,
    'default': 0.000010
  };
  const rate = prices[(tool || '').toLowerCase()] || prices.default;
  return (tokens || 0) * rate;
}

function updateCostDashboard() {
  const histSheet = SS.getSheetByName('📊 Konuşma Geçmişi');
  const dashSheet = SS.getSheetByName('💰 Maliyet Dashboard');
  const data = histSheet.getDataRange().getValues().slice(1);
  const tools = ['claude', 'gemini', 'perplexity', 'antigravity'];
  const summary = {};
  tools.forEach(t => summary[t] = { tokens: 0, cost: 0, count: 0 });
  data.forEach(row => {
    const tool = (row[2] || '').toLowerCase();
    if (summary[tool]) {
      summary[tool].tokens += row[6] || 0;
      summary[tool].cost += row[7] || 0;
      summary[tool].count += 1;
    }
  });
  dashSheet.getRange('A2:D5').clearContent();
  tools.forEach((t, i) => {
    dashSheet.getRange(i + 2, 1, 1, 4).setValues([[
      t, summary[t].count, summary[t].tokens, summary[t].cost.toFixed(4)
    ]]);
  });
}

function setupSheets() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sheetsConfig = [
    {
      name: '📋 Projeler',
      headers: ['Proje Adı', 'Birincil Araç', 'Cihaz', 'Aşama', 'Başlangıç Tarihi',
        'Son İşlem', 'Drive Klasör Linki', 'NotebookLM Linki', 'Deploy URL',
        'Toplam Token', 'Toplam Maliyet (USD)', 'Notlar'],
      color: '#1e88e5'
    },
    {
      name: '📊 Konuşma Geçmişi',
      headers: ['Tarih', 'Cihaz', 'Araç', 'Proje', 'Prompt Özeti', 'Yanıt Özeti',
        'Token', 'Maliyet (USD)', 'Dosya Linki', 'Klasör Linki'],
      color: '#43a047'
    },
    {
      name: '🌐 Hosting & Deploy Log',
      headers: ['Tarih', 'Alan Adı', 'İşlem', 'Araç', 'Durum', 'URL', 'Notlar'],
      color: '#fb8c00'
    },
    {
      name: '💰 Maliyet Dashboard',
      headers: ['Araç', 'İşlem Sayısı', 'Toplam Token', 'Toplam Maliyet (USD)'],
      color: '#8e24aa'
    },
    {
      name: '⚙️ Ayarlar',
      headers: ['Ayar Adı', 'Durum', 'Açıklama'],
      color: '#757575'
    }
  ];
  sheetsConfig.forEach(cfg => {
    let sheet = ss.getSheetByName(cfg.name);
    if (!sheet) sheet = ss.insertSheet(cfg.name);
    const headerRange = sheet.getRange(1, 1, 1, cfg.headers.length);
    headerRange.setValues([cfg.headers]);
    headerRange.setBackground(cfg.color).setFontColor('#ffffff').setFontWeight('bold');
    sheet.setFrozenRows(1);
  });
  SpreadsheetApp.getUi().alert('✅ Sheets kurulumu tamamlandı!');
}
