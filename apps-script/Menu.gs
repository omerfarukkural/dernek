function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('AI Asistan')
    .addItem('Maliyet Raporunu Guncelle', 'updateCostDashboard')
    .addItem('Sheets Kurulumunu Calistir', 'setupSheets')
    .addSeparator()
    .addItem('Drive Ana Klasorunu Ac', 'openBaseFolder')
    .addItem('Ayarlari Goster', 'showSettings')
    .addToUi();
}

function openBaseFolder() {
  const folder = getOrCreateBaseFolder();
  const ui = SpreadsheetApp.getUi();
  ui.alert('Drive Klasoru', folder.getUrl(), ui.ButtonSet.OK);
}

function showSettings() {
  const props = PropertiesService.getScriptProperties().getProperties();
  const masked = Object.keys(props).map(function(k) {
    var val = props[k];
    return k + ': ' + (val.length > 8 ? val.substring(0, 4) + '****' + val.slice(-4) : '****');
  }).join('\n');
  SpreadsheetApp.getUi().alert('Script Ayarlari', masked || 'Henuz ayar yok.', SpreadsheetApp.getUi().ButtonSet.OK);
}
