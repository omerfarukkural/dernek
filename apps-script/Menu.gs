function onOpen() {
  const ui = SpreadsheetApp.getUi();
  ui.createMenu('AI Asistan')
    .addItem('Sheets Kurulumunu Calistir', 'setupSheets')
    .addItem('Sosyal Medya Sayfalarini Kur', 'setupSocialSheets')
    .addItem('Maliyet Raporunu Guncelle', 'updateCostDashboard')
    .addSeparator()
    .addItem('Trend Konulari Goster', 'showTrends')
    .addItem('Drive Ana Klasorunu Ac', 'openBaseFolder')
    .addItem('Ayarlari Goster', 'showSettings')
    .addToUi();
}

function showTrends() {
  const topics = getTrendingTopics();
  const msg = topics.length
    ? topics.map((t, i) => `${i+1}. ${t.topic} (${t.category})`).join('\n')
    : 'Trend bulunamadi. Trendler sayfasina konu ekleyin.';
  SpreadsheetApp.getUi().alert('Top 5 Trend', msg, SpreadsheetApp.getUi().ButtonSet.OK);
}

function openBaseFolder() {
  const folder = getOrCreateBaseFolder();
  SpreadsheetApp.getUi().alert('Drive Klasoru', folder.getUrl(), SpreadsheetApp.getUi().ButtonSet.OK);
}

function showSettings() {
  const props = PropertiesService.getScriptProperties().getProperties();
  const masked = Object.keys(props).map(k => {
    const v = props[k];
    return k + ': ' + (v.length > 8 ? v.substring(0, 4) + '****' + v.slice(-4) : '****');
  }).join('\n');
  SpreadsheetApp.getUi().alert('Script Ayarlari', masked || 'Henuz ayar yok.', SpreadsheetApp.getUi().ButtonSet.OK);
}
