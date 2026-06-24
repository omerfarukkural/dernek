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
    switch (params.action || params.type) {
      case 'conversation': logConversation(params); break;
      case 'deploy':       logDeploy(params);       break;
      case 'logSocialPost':   logSocialPost(params);   break;
      case 'updatePostStatus':
        updatePostStatus(params.post_id, params.status, params.publish_url);
        break;
      case 'saveToDrive':
        saveToDrive(params);
        break;
      default:
        logConversation(params);
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
  switch (params.action) {
    case 'projects':    return jsonResponse(getProjectNames());
    case 'trends':      return jsonResponse(getTrendingTopics());
    case 'getContext':  return jsonResponse({ context: getContextForTopic(params.topic) });
    default:            return jsonResponse({ status: 'ok', version: '2.0.0' });
  }
}

function getContextForTopic(topic) {
  if (!topic) return '';
  const sheet = SS.getSheetByName('Konusma Gecmisi');
  if (!sheet) return '';
  const data = sheet.getDataRange().getValues().slice(1);
  const matches = data.filter(row => {
    const summary = (row[4] || '') + ' ' + (row[5] || '');
    return summary.toLowerCase().includes(topic.toLowerCase());
  });
  if (!matches.length) return '';
  return matches.slice(-3).map(row =>
    `[${row[0]}] ${row[2]}: ${row[5]}`
  ).join('\n\n');
}

function jsonResponse(data) {
  return ContentService.createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}
