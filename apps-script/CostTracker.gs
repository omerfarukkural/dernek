function checkMonthlyBudget() {
  var props = PropertiesService.getScriptProperties();
  var budget = parseFloat(props.getProperty('MONTHLY_BUDGET_USD') || '10');
  var sheet = SS.getSheetByName('Konusma Gecmisi');
  var data = sheet.getDataRange().getValues().slice(1);
  var now = new Date();
  var monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
  var monthCost = 0;
  data.forEach(function(row) {
    var rowDate = new Date(row[0]);
    if (rowDate >= monthStart) monthCost += parseFloat(row[7] || 0);
  });
  if (monthCost >= budget * 0.8) {
    var email = Session.getActiveUser().getEmail();
    MailApp.sendEmail(email,
      'AI Maliyet Uyarisi',
      'Bu ay ' + monthCost.toFixed(4) + ' USD harcandi. Butcenizin %80 ine ulasildi (' + budget + ' USD).'
    );
  }
}

function getTokenPrices() {
  return {
    'claude-3-opus': { input: 0.000015, output: 0.000075 },
    'claude-3-sonnet': { input: 0.000003, output: 0.000015 },
    'claude-3-haiku': { input: 0.00000025, output: 0.00000125 },
    'gemini-1.5-pro': { input: 0.0000035, output: 0.0000105 },
    'gemini-1.5-flash': { input: 0.00000035, output: 0.00000105 },
    'perplexity-sonar': { input: 0.000001, output: 0.000001 }
  };
}
