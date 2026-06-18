// Merkezi Web App — tüm cihazlardan POST alır
// Apps Script > Deploy > New Deployment > Web App olarak yayınlayın
// Script Properties'e WEBHOOK_SECRET ekleyin

const SECRET_TOKEN = PropertiesService.getScriptProperties().getProperty('WEBHOOK_SECRET');

function doPost(e) {
  try {
    const params = JSON.parse(e.postData.contents);
    if (params.token !== SECRET_TOKEN) {
      return jsonResponse({ error: 'Unauthorized' });
    }
    if (params.type === 'conversation') {
      logConversation(params);
    } else if (params.type === 'deploy') {
      logDeploy(params);
    }
    return jsonResponse({ success: true });
  } catch (err) {
    return jsonResponse({ error: err.toString() });
  }
}

function doGet(e) {
  const params = e.parameter;
  if (params.token !== SECRET_TOKEN) {
    return jsonResponse({ error: 'Unauthorized' });
  }
  if (params.action === 'projects') {
    return jsonResponse(getProjectNames());
  }
  return jsonResponse({ status: 'ok', version: '1.0.0' });
}

function jsonResponse(data) {
  return ContentService.createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}
