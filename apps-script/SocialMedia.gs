/**
 * SocialMedia.gs
 * Google Apps Script module for social media tracking
 * Dernek Social Media Pipeline — production-ready
 */

// ─── Constants ────────────────────────────────────────────────────────────────
var SOCIAL_SHEET_NAME   = '📱 Sosyal Medya';
var TRENDS_SHEET_NAME   = '🔥 Trendler';
// WEBHOOK_SECRET is read from Script Properties at runtime (set via Apps Script UI)

var SOCIAL_HEADERS = [
  'ID', 'Tarih', 'Hesap', 'Platform', 'İçerik Özeti',
  'Durum', 'Onay', 'Yayın URL', 'Etkileşim', 'Araç'
];

var TRENDS_HEADERS = [
  'ID', 'Konu', 'Kaynak', 'Skor', 'Etiketler', 'Eklenme Tarihi', 'Durum'
];

// ─── Sheet helpers ─────────────────────────────────────────────────────────────

/**
 * Returns (creating if needed) the named sheet in the active spreadsheet.
 * @param {string} name
 * @return {GoogleAppsScript.Spreadsheet.Sheet}
 */
function _getOrCreateSheet(name) {
  var ss    = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName(name);
  if (!sheet) {
    sheet = ss.insertSheet(name);
  }
  return sheet;
}

/**
 * Ensures the header row exists on the sheet.
 * @param {GoogleAppsScript.Spreadsheet.Sheet} sheet
 * @param {string[]} headers
 */
function _ensureHeaders(sheet, headers) {
  if (sheet.getLastRow() === 0) {
    var headerRange = sheet.getRange(1, 1, 1, headers.length);
    headerRange.setValues([headers]);
    headerRange.setFontWeight('bold');
    headerRange.setBackground('#1a73e8');
    headerRange.setFontColor('#ffffff');
    sheet.setFrozenRows(1);
  }
}

/**
 * Generates a simple unique ID: timestamp + random suffix.
 * @return {string}
 */
function _generateId() {
  return 'SP-' + Date.now() + '-' + Math.floor(Math.random() * 10000);
}

// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * Appends a new social post row to the "📱 Sosyal Medya" sheet.
 *
 * @param {Object} data
 * @param {string} data.account       — Hesap (e.g. "@bitebimuv")
 * @param {string} data.platform      — Platform (e.g. "Twitter", "Instagram")
 * @param {string} data.contentSummary — İçerik Özeti
 * @param {string} [data.status]      — Durum (default: "Taslak")
 * @param {string} [data.approval]    — Onay (default: "Bekliyor")
 * @param {string} [data.publishUrl]  — Yayın URL
 * @param {string} [data.engagement]  — Etkileşim
 * @param {string} [data.tool]        — Araç (e.g. "n8n", "Manuel")
 * @return {Object} { success: boolean, id: string, row: number }
 */
function logSocialPost(data) {
  try {
    var sheet = _getOrCreateSheet(SOCIAL_SHEET_NAME);
    _ensureHeaders(sheet, SOCIAL_HEADERS);

    var id   = data.id || _generateId();
    var now  = new Date();
    var row  = [
      id,
      now,
      data.account        || '',
      data.platform       || '',
      data.contentSummary || data.content         || '',
      data.status         || 'Taslak',
      data.approval       || 'Bekliyor',
      data.publishUrl     || data.publish_url     || '',
      data.engagement     || '',
      data.tool           || data.ai_tool         || 'n8n'
    ];

    sheet.appendRow(row);

    // Auto-resize columns on first few rows
    if (sheet.getLastRow() <= 5) {
      sheet.autoResizeColumns(1, SOCIAL_HEADERS.length);
    }

    return { success: true, id: id, row: sheet.getLastRow() };
  } catch (err) {
    Logger.log('logSocialPost error: ' + err.message);
    return { success: false, error: err.message };
  }
}

/**
 * Finds a row by post ID and updates its status, approval, and publish URL.
 *
 * @param {string} postId
 * @param {string} status       — new Durum value
 * @param {string} [publishUrl] — new Yayın URL (optional)
 * @return {Object} { success: boolean, row: number|null }
 */
function updatePostStatus(postId, status, publishUrl) {
  try {
    var sheet = _getOrCreateSheet(SOCIAL_SHEET_NAME);
    _ensureHeaders(sheet, SOCIAL_HEADERS);

    var lastRow = sheet.getLastRow();
    if (lastRow < 2) {
      return { success: false, error: 'Sheet is empty' };
    }

    var data = sheet.getRange(2, 1, lastRow - 1, SOCIAL_HEADERS.length).getValues();

    // Column indices (0-based within data array)
    var COL_ID         = 0;  // A
    var COL_STATUS     = 5;  // F
    var COL_APPROVAL   = 6;  // G
    var COL_PUBLISH_URL = 7; // H

    for (var i = 0; i < data.length; i++) {
      if (String(data[i][COL_ID]) === String(postId)) {
        var sheetRow = i + 2; // +1 for header, +1 for 1-based index

        sheet.getRange(sheetRow, COL_STATUS + 1).setValue(status);

        if (status === 'Onaylandı' || status === 'Yayınlandı') {
          sheet.getRange(sheetRow, COL_APPROVAL + 1).setValue('Onaylandı');
        } else if (status === 'Reddedildi') {
          sheet.getRange(sheetRow, COL_APPROVAL + 1).setValue('Reddedildi');
        }

        if (publishUrl) {
          sheet.getRange(sheetRow, COL_PUBLISH_URL + 1).setValue(publishUrl);
        }

        return { success: true, row: sheetRow };
      }
    }

    return { success: false, error: 'Post ID not found: ' + postId };
  } catch (err) {
    Logger.log('updatePostStatus error: ' + err.message);
    return { success: false, error: err.message };
  }
}

/**
 * Reads the "🔥 Trendler" sheet and returns the top 5 active trends
 * sorted by Skor descending.
 *
 * @return {Object[]} Array of trend objects { id, topic, source, score, tags, date, status }
 */
function getTrendingTopics() {
  try {
    var sheet = _getOrCreateSheet(TRENDS_SHEET_NAME);
    _ensureHeaders(sheet, TRENDS_HEADERS);

    var lastRow = sheet.getLastRow();
    if (lastRow < 2) {
      return [];
    }

    var data = sheet.getRange(2, 1, lastRow - 1, TRENDS_HEADERS.length).getValues();

    var trends = [];
    for (var i = 0; i < data.length; i++) {
      var row = data[i];
      var status = String(row[6]).trim();
      if (status === '' || status === 'Aktif') {
        trends.push({
          id     : row[0],
          topic  : row[1],
          source : row[2],
          score  : Number(row[3]) || 0,
          tags   : row[4],
          date   : row[5],
          status : row[6]
        });
      }
    }

    // Sort by score descending and return top 5
    trends.sort(function(a, b) { return b.score - a.score; });
    return trends.slice(0, 5);
  } catch (err) {
    Logger.log('getTrendingTopics error: ' + err.message);
    return [];
  }
}

/**
 * Adds or updates a trend entry in the "🔥 Trendler" sheet.
 * Used by the Perplexity pipeline to auto-populate trends.
 *
 * @param {Object} trend
 * @param {string} trend.topic
 * @param {string} [trend.source]
 * @param {number} [trend.score]
 * @param {string} [trend.tags]
 * @return {Object} { success: boolean, id: string }
 */
function upsertTrend(trend) {
  try {
    var sheet = _getOrCreateSheet(TRENDS_SHEET_NAME);
    _ensureHeaders(sheet, TRENDS_HEADERS);

    // Check if topic already exists
    var lastRow = sheet.getLastRow();
    if (lastRow >= 2) {
      var data = sheet.getRange(2, 1, lastRow - 1, 2).getValues();
      for (var i = 0; i < data.length; i++) {
        if (String(data[i][1]).toLowerCase() === String(trend.topic).toLowerCase()) {
          var sheetRow = i + 2;
          sheet.getRange(sheetRow, 4).setValue(trend.score || 0);
          sheet.getRange(sheetRow, 7).setValue('Aktif');
          return { success: true, id: data[i][0], updated: true };
        }
      }
    }

    // New trend
    var id  = 'TR-' + Date.now();
    var row = [
      id,
      trend.topic,
      trend.source || 'Perplexity',
      trend.score  || 0,
      trend.tags   || '',
      new Date(),
      'Aktif'
    ];
    sheet.appendRow(row);
    return { success: true, id: id, updated: false };
  } catch (err) {
    Logger.log('upsertTrend error: ' + err.message);
    return { success: false, error: err.message };
  }
}

// ─── doPost handler ────────────────────────────────────────────────────────────

// NOTE: doPost and doGet are defined in Code.gs — SocialMedia.gs only exports helper functions.

/**
 * Helper: builds a JSON ContentService response.
 * @param {Object} data
 * @return {GoogleAppsScript.Content.TextOutput}
 */
function _jsonResponse(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}

// ─── Setup / Initialisation ────────────────────────────────────────────────────

/**
 * Run once manually to create both sheets with correct headers and formatting.
 */
function setupSocialSheets() {
  // Social media sheet
  var socialSheet = _getOrCreateSheet(SOCIAL_SHEET_NAME);
  socialSheet.clearContents();
  _ensureHeaders(socialSheet, SOCIAL_HEADERS);
  socialSheet.setColumnWidth(1, 160);  // ID
  socialSheet.setColumnWidth(2, 130);  // Tarih
  socialSheet.setColumnWidth(3, 140);  // Hesap
  socialSheet.setColumnWidth(4, 110);  // Platform
  socialSheet.setColumnWidth(5, 280);  // İçerik Özeti
  socialSheet.setColumnWidth(6, 110);  // Durum
  socialSheet.setColumnWidth(7, 110);  // Onay
  socialSheet.setColumnWidth(8, 220);  // Yayın URL
  socialSheet.setColumnWidth(9, 110);  // Etkileşim
  socialSheet.setColumnWidth(10, 100); // Araç

  // Trends sheet
  var trendsSheet = _getOrCreateSheet(TRENDS_SHEET_NAME);
  trendsSheet.clearContents();
  _ensureHeaders(trendsSheet, TRENDS_HEADERS);
  trendsSheet.setColumnWidth(1, 160);  // ID
  trendsSheet.setColumnWidth(2, 250);  // Konu
  trendsSheet.setColumnWidth(3, 130);  // Kaynak
  trendsSheet.setColumnWidth(4, 80);   // Skor
  trendsSheet.setColumnWidth(5, 200);  // Etiketler
  trendsSheet.setColumnWidth(6, 130);  // Eklenme Tarihi
  trendsSheet.setColumnWidth(7, 100);  // Durum

  SpreadsheetApp.getActiveSpreadsheet().toast('Sheets initialized successfully!', '✅ Setup Complete', 5);
  Logger.log('setupSheets completed successfully.');
}
