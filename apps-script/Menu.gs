function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('AI Asistan')
    .addItem('Sheets Kurulumunu Calistir', 'setupSheets')
    .addItem('Maliyet Raporunu Guncelle', 'updateCostDashboard')
    .addSeparator()
    .addItem('Drive Ana Klasorunu Ac', 'openBaseFolder')
    .addItem('Ayarlari Goster', 'showSettings')
    .addToUi();
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
