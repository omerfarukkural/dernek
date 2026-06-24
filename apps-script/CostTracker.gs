function checkMonthlyBudget() {
  const props = PropertiesService.getScriptProperties();
  const budget = parseFloat(props.getProperty('MONTHLY_BUDGET_USD') || '10');
  const sheet = SS.getSheetByName('Konusma Gecmisi');
  if (!sheet) return;
  const data = sheet.getDataRange().getValues().slice(1);
  const monthStart = new Date();
  monthStart.setDate(1); monthStart.setHours(0, 0, 0, 0);
  let monthCost = 0;
  data.forEach(row => {
    if (new Date(row[0]) >= monthStart) monthCost += parseFloat(row[7] || 0);
  });
  if (monthCost >= budget * 0.8) {
    MailApp.sendEmail(
      Session.getActiveUser().getEmail(),
      'AI Maliyet Uyarisi - %80 Esigi Asildi',
      'Bu ay ' + monthCost.toFixed(4) + ' USD harcandi. Butceniz: ' + budget + ' USD.\n\nLutfen AI kullanim siklignizi gozden gecirin.'
    );
  }
}

function getTokenPrices() {
  return {
    'claude-opus-4':    { input: 0.000015, output: 0.000075 },
    'claude-sonnet-4':  { input: 0.000003, output: 0.000015 },
    'claude-haiku-4':   { input: 0.00000025, output: 0.00000125 },
    'gemini-2.5-pro':   { input: 0.00000125, output: 0.000010 },
    'gemini-2.5-flash': { input: 0.0000003, output: 0.0000025 },
    'perplexity-sonar': { input: 0.000001, output: 0.000001 }
  };
}
